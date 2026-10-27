<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Integration;

use stdClass;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\EntityManagerInterface;
use F3lix\MessageBundle\Outbox\OutboxWriter;
use F3lix\MessageBundle\Outbox\OutboxEntryStore;
use F3lix\MessageBundle\Logging\AmqpLogHandler;
use F3lix\MessageBundle\Consumer\RetryingExecutor;
use F3lix\MessageBundle\Outbox\Entity\OutboxEntry;
use F3lix\MessageBundle\Testing\AmqpMessageAssertions;
use F3lix\MessageBundle\Testing\EventFormatAssertions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use F3lix\MessageBundle\Deletion\DeletionRequestSerializer;
use F3lix\MessageBundle\Logging\SensitiveFieldRedactionProcessor;
use Symfony\Component\Messenger\Envelope;
use F3lix\MessageBundle\Message\RawMessage;
use F3lix\MessageBundle\Message\BodySerializer;
use F3lix\MessageBundle\Deletion\DeletionRequest;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[RequiresPhpExtension('amqp')]
#[RequiresPhpExtension('pdo_sqlite')]
final class BundleIntegrationTest extends KernelTestCase
{
    use AmqpMessageAssertions;
    use EventFormatAssertions;

    private const string PERSON_ID = '018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a';

    private EntityManagerInterface $entityManager;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testWritesWithTheTransactionAndPublishesInTheMessageFormat(): void
    {
        $writer = self::service(OutboxWriter::class);

        $this->entityManager->wrapInTransaction(static function () use ($writer): void {
            $writer->append('person.created', self::PERSON_ID, [
                'id'        => self::PERSON_ID,
                'actorId'   => 'f47c1c2e-8a57-4c0e-9d3b-6f2a1b7e5c90',
                'timestamp' => '2026-07-21T10:00:00.000Z',
            ]);
            $writer->append('person.deleted', self::PERSON_ID, [
                'id'        => self::PERSON_ID,
                'timestamp' => '2026-07-21T11:00:00.000Z',
                'ticketId'  => 'ret-2026-004821',
            ]);
        });
        $this->entityManager->clear();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('outbox:publish'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 zugestellt', $tester->getDisplay());

        $central = self::transport('messenger.transport.domain_events')->getSent();
        $service = self::transport('messenger.transport.test_service')->getSent();
        self::assertCount(1, $central);
        self::assertCount(1, $service);
        self::assertCreatedEvent(self::assertPublishedEvent($central[0], 'person.created', TestKernel::SERVICE));
        self::assertDeletedEvent(self::assertPublishedEvent($service[0], 'person.deleted', TestKernel::SERVICE));
        self::assertSame([], self::service(OutboxEntryStore::class)->findAllOrdered());
    }

    public function testKeepsTheFieldOrderAndEmptyObjectsOfThePayload(): void
    {
        $writer = self::service(OutboxWriter::class);
        $this->entityManager->wrapInTransaction(static function () use ($writer): void {
            $writer->append('person.updated', self::PERSON_ID, [
                'id'        => self::PERSON_ID,
                'actorId'   => 'f47c1c2e-8a57-4c0e-9d3b-6f2a1b7e5c90',
                'timestamp' => '2026-07-21T10:00:00.000Z',
                'delta'     => [[
                    'field'    => 'address',
                    'operation' => 'added',
                    'oldValue' => null,
                    'newValue' => new stdClass(),
                    'masked'   => true,
                ]],
            ]);
        });
        $this->entityManager->clear();

        $entries = self::service(OutboxEntryStore::class)->findAllOrdered();

        self::assertCount(1, $entries);
        self::assertSame(
            '{"id":"018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a","actorId":"f47c1c2e-8a57-4c0e-9d3b-6f2a1b7e5c90",'
            . '"timestamp":"2026-07-21T10:00:00.000Z","delta":[{"field":"address","operation":"added",'
            . '"oldValue":null,"newValue":{},"masked":true}]}',
            $entries[0]->getPayload(),
        );
        self::assertUpdatedEvent($entries[0]->getPayload());
    }

    public function testRetryFailedResetsFailedEntriesInTheDatabase(): void
    {
        $writer = self::service(OutboxWriter::class);
        $entry = $writer->append('person.updated', self::PERSON_ID, ['id' => self::PERSON_ID]);

        for ($attempt = 0; $attempt < OutboxEntry::MAX_ATTEMPTS; ++$attempt) {
            $entry->recordFailedAttempt();
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(1, self::service(OutboxEntryStore::class)->retryFailed($entry->getId()));
        self::assertSame(0, self::service(OutboxEntryStore::class)->retryFailed());
        self::assertFalse(self::service(OutboxEntryStore::class)->findAllOrdered()[0]->isFailed());
    }

    public function testWiresTheConsumerAndTheLogging(): void
    {
        $container = self::getContainer();

        self::assertInstanceOf(InMemoryTransport::class, $container->get('messenger.transport.deletion_requests'));
        self::assertSame(10, self::service(RetryingExecutor::class)->maxAttempts());

        $request = self::serializer(DeletionRequestSerializer::class)
            ->decode(['body' => '{"id":"x","ticketId":"ret-1"}'])
            ->getMessage();
        self::assertInstanceOf(DeletionRequest::class, $request);

        $encoded = self::serializer(BodySerializer::class)->encode(new Envelope(new RawMessage('{"id":"x"}')));
        self::assertEncodedWithoutHeaders($encoded);

        $handler = $container->get('message.logging.amqp_handler');
        self::assertInstanceOf(AmqpLogHandler::class, $handler);
        self::assertTrue($container->has(SensitiveFieldRedactionProcessor::class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * Liefert einen Transport-Serializer, wie Symfony ihn verwendet, ggf. umhüllt vom SigningSerializer.
     */
    private static function serializer(string $id): SerializerInterface
    {
        $serializer = self::getContainer()->get($id);
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
    }

    private static function transport(string $id): InMemoryTransport
    {
        $transport = self::getContainer()->get($id);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
