<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Message;

/**
 * AMQP properties of the message format, for domain events as well as for log events.
 */
final class AmqpProperties
{
    public const string CONTENT_TYPE = 'application/json';
    public const int DELIVERY_MODE_PERSISTENT = 2;

    /**
     * Builds the AMQP properties of a message.
     *
     * @param string $messageId UUID v7: for events that of the outbox entry, for log events a new one per log event
     * @param string $type      routing key, e.g. person.updated or error.person-service
     * @param string $appId     name of the service
     * @param int    $timestamp point in time of publishing as Unix time in seconds
     *
     * @return array{
     *     message_id: string,
     *     type: string,
     *     app_id: string,
     *     content_type: string,
     *     timestamp: int,
     *     delivery_mode: int,
     * } the AMQP properties
     */
    public static function of(string $messageId, string $type, string $appId, int $timestamp): array
    {
        return [
            'message_id'    => $messageId,
            'type'          => $type,
            'app_id'        => $appId,
            'content_type'  => self::CONTENT_TYPE,
            'timestamp'     => $timestamp,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
        ];
    }
}
