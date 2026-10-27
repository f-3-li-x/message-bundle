<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Hands the message of an outbox entry over to RabbitMQ and reports whether the broker confirmed it.
 */
interface OutboxSender
{
    /**
     * Publishes the message of an outbox entry to its exchange and waits for the broker's confirmation. If the
     * method returns without an exception, the delivery is confirmed.
     *
     * @param OutboxEntry $entry entry to deliver
     *
     * @return void
     *
     * @throws MessageRejectedException   if the broker rejects the message or does not confirm it
     * @throws BrokerUnavailableException if RabbitMQ is unreachable
     */
    public function send(OutboxEntry $entry): void;
}
