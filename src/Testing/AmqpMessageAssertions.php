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
 * Assertions for the message format: AMQP properties, routing key and body without headers.
 */
trait AmqpMessageAssertions
{
    /**
     * Checks the AMQP properties of a message; their order does not matter.
     *
     * @param array<array-key, mixed> $attributes AMQP properties
     * @param string                  $type       expected routing key, e.g. person.updated
     * @param string                  $appId      expected name of the service
     *
     * @return void
     */
    public static function assertAmqpProperties(array $attributes, string $type, string $appId): void
    {
        Assert::assertEqualsCanonicalizing(
            ['message_id', 'type', 'app_id', 'content_type', 'timestamp', 'delivery_mode'],
            array_keys($attributes),
            'AMQP properties',
        );

        $messageId = $attributes['message_id'];
        Assert::assertIsString($messageId, 'message_id is not a string.');
        Assert::assertTrue(Uuid::isValid($messageId), 'message_id is not a UUID.');
        Assert::assertInstanceOf(UuidV7::class, Uuid::fromString($messageId), 'message_id is not a UUID v7.');
        Assert::assertSame($type, $attributes['type'], 'type');
        Assert::assertSame($appId, $attributes['app_id'], 'app_id');
        Assert::assertSame(AmqpProperties::CONTENT_TYPE, $attributes['content_type'], 'content_type');
        Assert::assertIsInt($attributes['timestamp'], 'timestamp is not a Unix time in seconds.');
        Assert::assertSame(AmqpProperties::DELIVERY_MODE_PERSISTENT, $attributes['delivery_mode'], 'delivery_mode');
    }

    /**
     * Checks an envelope published by the outbox publisher and returns its body.
     *
     * @param Envelope $envelope published envelope, e.g. from an in-memory transport
     * @param string   $type     expected event name, also the routing key
     * @param string   $appId    expected name of the service
     *
     * @return string the body, the payload as JSON
     */
    public static function assertPublishedEvent(Envelope $envelope, string $type, string $appId): string
    {
        $stamp = $envelope->last(AmqpStamp::class);
        Assert::assertInstanceOf(AmqpStamp::class, $stamp, 'The envelope carries no AmqpStamp.');
        Assert::assertSame($type, $stamp->getRoutingKey(), 'Routing key');
        self::assertAmqpProperties($stamp->getAttributes(), $type, $appId);

        $message = $envelope->getMessage();
        Assert::assertInstanceOf(RawMessage::class, $message, 'The message does not carry the body of the event.');

        return $message->body;
    }

    /**
     * Checks that an encoded message carries no headers.
     *
     * @param array<array-key, mixed> $encodedEnvelope encoded message of a transport serializer
     *
     * @return void
     */
    public static function assertEncodedWithoutHeaders(array $encodedEnvelope): void
    {
        Assert::assertSame([], $encodedEnvelope['headers'] ?? [], 'The message carries headers.');
        Assert::assertIsString($encodedEnvelope['body'] ?? null, 'The message has no body.');
    }
}
