<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Message;

/**
 * AMQP-Properties des Nachrichtenformats, für Domain Events wie für Log-Events.
 */
final class AmqpProperties
{
    public const string CONTENT_TYPE = 'application/json';
    public const int DELIVERY_MODE_PERSISTENT = 2;

    /**
     * Bildet die AMQP-Properties einer Nachricht.
     *
     * @param string $messageId UUID v7: bei Events die des Outbox-Eintrags, bei Log-Events eine neue je Log-Event
     * @param string $type      Routing-Key, z. B. person.updated oder error.person-service
     * @param string $appId     Name des Service
     * @param int    $timestamp Zeitpunkt der Veröffentlichung als Unix-Zeit in Sekunden
     *
     * @return array{
     *     message_id: string,
     *     type: string,
     *     app_id: string,
     *     content_type: string,
     *     timestamp: int,
     *     delivery_mode: int,
     * } die AMQP-Properties
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
