<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Outbox;

use Throwable;
use AMQPException;
use LogicException;
use DateTimeImmutable;
use AMQPQueueException;
use AMQPChannelException;
use AMQPExchangeException;
use AMQPConnectionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use ACAT\MessageBundle\Outbox\OutboxSender;
use ACAT\MessageBundle\Tests\Support\OutboxEntries;
use PHPUnit\Framework\Attributes\DataProvider;
use ACAT\MessageBundle\Outbox\MessengerOutboxSender;
use ACAT\MessageBundle\Testing\AmqpMessageAssertions;
use ACAT\MessageBundle\Testing\RecordingMessengerSender;
use ACAT\MessageBundle\Outbox\MessageRejectedException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use ACAT\MessageBundle\Outbox\BrokerUnavailableException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;

#[RequiresPhpExtension('amqp')]
final class MessengerOutboxSenderTest extends TestCase
{
    use AmqpMessageAssertions;

    private const string ENTRY_ID = '019a0000-0000-7000-8000-000000000001';

    private RecordingMessengerSender $eventsTransport;

    private RecordingMessengerSender $serviceTransport;

    private OutboxSender $sender;

    protected function setUp(): void
    {
        $this->eventsTransport = new RecordingMessengerSender();
        $this->serviceTransport = new RecordingMessengerSender();
        $this->sender = new MessengerOutboxSender(
            ['domain-events' => $this->eventsTransport, 'person-service' => $this->serviceTransport],
            'person-service',
            new MockClock('2026-07-21T10:00:05Z'),
        );
    }

    public function testPublishesToTheTransportOfTheEntrysExchangeInTheMessageFormat(): void
    {
        $entry = OutboxEntries::pending(self::ENTRY_ID, eventType: 'person.deleted', exchange: 'person-service');

        $this->sender->send($entry);

        self::assertSame([], $this->eventsTransport->envelopes());
        self::assertCount(1, $this->serviceTransport->envelopes());

        $envelope = $this->serviceTransport->envelopes()[0];
        $body = self::assertPublishedEvent($envelope, 'person.deleted', 'person-service');

        self::assertSame($entry->getPayload(), $body);
        $stamp = $envelope->last(AmqpStamp::class);
        self::assertInstanceOf(AmqpStamp::class, $stamp);
        self::assertSame(self::ENTRY_ID, $stamp->getAttributes()['message_id']);
        self::assertSame(
            (new DateTimeImmutable('2026-07-21T10:00:05Z'))->getTimestamp(),
            $stamp->getAttributes()['timestamp'],
        );
    }

    public function testRefusesAnExchangeWithoutTransport(): void
    {
        $this->expectException(LogicException::class);

        $this->sender->send(OutboxEntries::pending(self::ENTRY_ID, exchange: 'unknown-exchange'));
    }

    /**
     * @return iterable<string, array{Throwable, class-string}>
     */
    public static function transportFailures(): iterable
    {
        yield 'Verbindungsaufbau gescheitert' => [
            new TransportException('x', 0, new AMQPConnectionException('x')),
            BrokerUnavailableException::class,
        ];
        yield 'Verbindung ohne nähere Art abgebrochen' => [
            new TransportException('x', 0, new AMQPException('x')),
            BrokerUnavailableException::class,
        ];
        yield 'Fehler auf dem Kanal' => [
            new TransportException('x', 0, new AMQPChannelException('x')),
            MessageRejectedException::class,
        ];
        yield 'Fehler am Exchange' => [
            new TransportException('x', 0, new AMQPExchangeException('x')),
            MessageRejectedException::class,
        ];
        yield 'Bestätigung bleibt aus' => [
            new TransportException('x', 0, new AMQPQueueException('x')),
            MessageRejectedException::class,
        ];
        yield 'negative Bestätigung' => [new TransportException('nack'), MessageRejectedException::class];
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('transportFailures')]
    public function testClassifiesTransportFailures(Throwable $failure, string $expected): void
    {
        $this->eventsTransport->failWith($failure);

        $this->expectException($expected);

        $this->sender->send(OutboxEntries::pending(self::ENTRY_ID));
    }
}
