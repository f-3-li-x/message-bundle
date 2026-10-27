<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Message;

/**
 * Nachricht für Symfony Messenger, die den Body unverändert trägt, wie er an RabbitMQ geht. Routing-Key und
 * AMQP-Properties trägt der AmqpStamp des Umschlags.
 */
final readonly class RawMessage
{
    /**
     * Erzeugt die Nachricht.
     *
     * @param string $body Payload des Events als JSON
     */
    public function __construct(public string $body)
    {
    }
}
