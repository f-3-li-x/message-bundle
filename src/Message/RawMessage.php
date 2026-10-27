<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Message;

/**
 * Message for Symfony Messenger that carries the body unchanged, as it is sent to RabbitMQ. The AmqpStamp of the
 * envelope carries routing key and AMQP properties.
 */
final readonly class RawMessage
{
    /**
     * Creates the message.
     *
     * @param string $body payload of the event as JSON
     */
    public function __construct(public string $body)
    {
    }
}
