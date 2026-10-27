<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use F3lix\MessageBundle\Message\BodySerializer;
use F3lix\MessageBundle\Outbox\OutboxPublisher;
use F3lix\MessageBundle\Outbox\Command\PublishOutboxCommand;
use F3lix\MessageBundle\Outbox\Command\RetryFailedOutboxCommand;
use F3lix\MessageBundle\Connection\RabbitMqTransportFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use F3lix\MessageBundle\DependencyInjection\MessageExtension;
use F3lix\MessageBundle\Deletion\DeletionRequestSerializer;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use F3lix\MessageBundle\Logging\SensitiveFieldRedactionProcessor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class MessageExtensionTest extends TestCase
{
    public function testPrependsTheBusAndTheTransportsOfTheService(): void
    {
        $container = self::container('prod', [
            'service'   => 'organisation-service',
            'outbox'    => ['central_exchange' => 'domain-events'],
            'consumers' => [
                'deletion_requests'        => ['queue' => 'organisation-service-deletion-requests'],
                'person_deletion_requests' => ['queue' => 'organisation-service-person-deletion-requests'],
            ],
        ]);

        $messenger = self::messengerConfig($container);

        self::assertSame(
            [
                'add_default_stamps_middleware',
                'add_bus_name_stamp_middleware',
                'dispatch_after_current_bus',
                'decode_failed_message_middleware',
                'failed_message_processing_middleware',
                'send_message',
                'handle_message',
            ],
            $messenger['buses']['messenger.bus.default']['middleware'],
        );
        self::assertSame(
            ['domain_events', 'organisation_service', 'deletion_requests', 'person_deletion_requests'],
            array_keys($messenger['transports']),
        );
        self::assertSame(
            [
                'dsn'        => RabbitMqTransportFactory::DSN,
                'serializer' => BodySerializer::class,
                'options'    => [
                    'auto_setup'      => false,
                    'confirm_timeout' => 10,
                    'queues'          => [],
                    'exchange'        => ['name' => 'organisation-service', 'type' => 'topic'],
                ],
            ],
            $messenger['transports']['organisation_service'],
        );
        self::assertSame(
            [
                'dsn'            => RabbitMqTransportFactory::DSN,
                'serializer'     => DeletionRequestSerializer::class,
                'options'        => [
                    'auto_setup' => false,
                    'queues'     => ['organisation-service-person-deletion-requests' => null],
                ],
                'retry_strategy' => ['max_retries' => 0],
            ],
            $messenger['transports']['person_deletion_requests'],
        );
    }

    public function testWithoutCentralExchangeOnlyTheTransportOfTheService(): void
    {
        $container = self::container('prod', ['service' => 'person-service']);

        self::assertSame(['person_service'], array_keys(self::messengerConfig($container)['transports']));
        self::assertNull($container->getParameter('message.outbox.central_exchange'));
    }

    public function testNamesTheCommandsInTheDefaultNamespace(): void
    {
        $container = self::container('prod', ['service' => 'person-service']);

        self::assertSame(
            [['command' => 'outbox:publish']],
            $container->getDefinition(PublishOutboxCommand::class)->getTag('console.command'),
        );
        self::assertSame(
            [['command' => 'outbox:retry-failed']],
            $container->getDefinition(RetryFailedOutboxCommand::class)->getTag('console.command'),
        );
    }

    public function testNamesTheCommandsInAConfiguredNamespace(): void
    {
        $container = self::container('prod', [
            'service' => 'person-service',
            'outbox'  => ['command_namespace' => 'billing:outbox'],
        ]);

        self::assertSame('billing:outbox:publish', $container->getDefinition(PublishOutboxCommand::class)->getArgument('$name'));
        self::assertSame(
            [['command' => 'billing:outbox:retry-failed']],
            $container->getDefinition(RetryFailedOutboxCommand::class)->getTag('console.command'),
        );
    }

    public function testRejectsAnInvalidCommandNamespace(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::container('prod', ['service' => 'person-service', 'outbox' => ['command_namespace' => 'Outbox Kommandos']]);
    }

    public function testAllTransportsRunInMemoryInTheTestEnvironment(): void
    {
        $container = self::container('test', [
            'service'   => 'person-service',
            'consumers' => ['deletion_requests' => ['queue' => 'person-service-deletion-requests']],
        ]);

        foreach (self::messengerConfig($container)['transports'] as $transport) {
            self::assertSame('in-memory://', $transport['dsn']);
        }
    }

    public function testPrependsTheMappingOfTheOutboxForDoctrine(): void
    {
        $container = self::container('prod', ['service' => 'person-service']);
        /** @var array{orm: array{mappings: array{MessageBundle: array{prefix: string}}}, dbal: array{types: array<string, string>}} $doctrine */
        $doctrine = $container->getExtensionConfig('doctrine')[0];

        self::assertSame('F3lix\MessageBundle\Outbox\Entity', $doctrine['orm']['mappings']['MessageBundle']['prefix']);
        self::assertArrayHasKey('message_utc_datetime_ms', $doctrine['dbal']['types']);
    }

    public function testWithoutOutboxNeitherPublishTransportsNorOutboxServices(): void
    {
        $container = self::container('prod', ['service' => 'person-service', 'outbox' => ['enabled' => false]]);

        self::assertSame([], self::messengerConfig($container)['transports']);
        self::assertSame([], $container->getExtensionConfig('doctrine'));
        self::assertFalse($container->hasDefinition(OutboxPublisher::class));
    }

    public function testRegistersTheRedactionProcessorOnlyWithSensitiveFields(): void
    {
        $without = self::container('prod', ['service' => 'organisation-service']);
        $with = self::container('prod', ['service' => 'person-service', 'logging' => ['redact_fields' => ['firstName']]]);

        self::assertFalse($without->hasDefinition(SensitiveFieldRedactionProcessor::class));
        self::assertSame(
            [['firstName']],
            $with->getDefinition(SensitiveFieldRedactionProcessor::class)->getArguments(),
        );
        self::assertTrue($with->getDefinition(SensitiveFieldRedactionProcessor::class)->hasTag('monolog.processor'));
    }

    public function testDisablesTheAmqpLogHandlerInTheTestEnvironment(): void
    {
        self::assertFalse(self::container('test', ['service' => 'person-service'])->getParameter('message.logging.enabled'));
        self::assertTrue(self::container('prod', ['service' => 'person-service'])->getParameter('message.logging.enabled'));
    }

    public function testRequiresTheServiceName(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::container('prod', []);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function container(string $environment, array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);
        $container->registerExtension(new DoctrineExtension());

        $extension = new MessageExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('message', $config);

        $extension->prepend($container);
        $extension->load([$config], $container);

        return $container;
    }

    /**
     * @return array{
     *     buses: array<string, array{middleware: list<string>}>,
     *     transports: array<string, array<string, mixed>>,
     * }
     */
    private static function messengerConfig(ContainerBuilder $container): array
    {
        /** @var array{buses: array<string, array{middleware: list<string>}>, transports: array<string, array<string, mixed>>} $messenger */
        $messenger = $container->getExtensionConfig('framework')[0]['messenger'];

        return $messenger;
    }
}
