<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\Assert;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Messenger\Envelope;
use ACAT\MessageBundle\Message\RawMessage;
use ACAT\MessageBundle\Message\AmqpProperties;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;

/**
 * Assertions für das Nachrichtenformat: AMQP-Properties, Routing-Key und Body ohne Header.
 */
trait AmqpMessageAssertions
{
    /**
     * Prüft die AMQP-Properties einer Nachricht; ihre Reihenfolge spielt keine Rolle.
     *
     * @param array<array-key, mixed> $attributes AMQP-Properties
     * @param string                  $type       erwarteter Routing-Key, z. B. person.updated
     * @param string                  $appId      erwarteter Name des Service
     *
     * @return void
     */
    public static function assertAmqpProperties(array $attributes, string $type, string $appId): void
    {
        Assert::assertEqualsCanonicalizing(
            ['message_id', 'type', 'app_id', 'content_type', 'timestamp', 'delivery_mode'],
            array_keys($attributes),
            'AMQP-Properties',
        );

        $messageId = $attributes['message_id'];
        Assert::assertIsString($messageId, 'message_id ist kein String.');
        Assert::assertTrue(Uuid::isValid($messageId), 'message_id ist keine UUID.');
        Assert::assertInstanceOf(UuidV7::class, Uuid::fromString($messageId), 'message_id ist keine UUID v7.');
        Assert::assertSame($type, $attributes['type'], 'type');
        Assert::assertSame($appId, $attributes['app_id'], 'app_id');
        Assert::assertSame(AmqpProperties::CONTENT_TYPE, $attributes['content_type'], 'content_type');
        Assert::assertIsInt($attributes['timestamp'], 'timestamp ist keine Unix-Zeit in Sekunden.');
        Assert::assertSame(AmqpProperties::DELIVERY_MODE_PERSISTENT, $attributes['delivery_mode'], 'delivery_mode');
    }

    /**
     * Prüft einen veröffentlichten Umschlag des Outbox-Publishers und liefert seinen Body.
     *
     * @param Envelope $envelope veröffentlichter Umschlag, z. B. aus einem In-Memory-Transport
     * @param string   $type     erwarteter Event-Name, zugleich Routing-Key
     * @param string   $appId    erwarteter Name des Service
     *
     * @return string der Body, das Payload als JSON
     */
    public static function assertPublishedEvent(Envelope $envelope, string $type, string $appId): string
    {
        $stamp = $envelope->last(AmqpStamp::class);
        Assert::assertInstanceOf(AmqpStamp::class, $stamp, 'Der Umschlag trägt keinen AmqpStamp.');
        Assert::assertSame($type, $stamp->getRoutingKey(), 'Routing-Key');
        self::assertAmqpProperties($stamp->getAttributes(), $type, $appId);

        $message = $envelope->getMessage();
        Assert::assertInstanceOf(RawMessage::class, $message, 'Die Nachricht trägt nicht den Body des Events.');

        return $message->body;
    }

    /**
     * Prüft, dass eine kodierte Nachricht keine Header trägt.
     *
     * @param array<array-key, mixed> $encodedEnvelope kodierte Nachricht eines Transport-Serializers
     *
     * @return void
     */
    public static function assertEncodedWithoutHeaders(array $encodedEnvelope): void
    {
        Assert::assertSame([], $encodedEnvelope['headers'] ?? [], 'Die Nachricht trägt Header.');
        Assert::assertIsString($encodedEnvelope['body'] ?? null, 'Die Nachricht hat keinen Body.');
    }
}
