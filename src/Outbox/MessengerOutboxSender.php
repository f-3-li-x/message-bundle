<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Throwable;
use AMQPException;
use LogicException;
use AMQPQueueException;
use AMQPChannelException;
use AMQPExchangeException;
use AMQPConnectionException;
use Psr\Clock\ClockInterface;
use ACAT\MessageBundle\Message\RawMessage;
use Symfony\Component\Messenger\Envelope;
use ACAT\MessageBundle\Message\AmqpProperties;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Publishes outbox entries to RabbitMQ via the transports of Symfony Messenger. The transports use publisher
 * confirms; if the transport returns without an error, the broker has confirmed the message.
 */
final readonly class MessengerOutboxSender implements OutboxSender
{
    /**
     * Creates the sender.
     *
     * @param array<string, SenderInterface> $senders transport per exchange, keyed by the name of the exchange
     * @param string                         $appId   name of the service, AMQP property app_id
     * @param ClockInterface                 $clock   clock for the AMQP property timestamp
     */
    public function __construct(
        private array $senders,
        private string $appId,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Publishes the message of an outbox entry to its exchange. The body is the payload; routing key and AMQP
     * properties come from AmqpProperties.
     *
     * @param OutboxEntry $entry entry to deliver
     *
     * @return void
     *
     * @throws MessageRejectedException   if the broker rejects the message or does not confirm it
     * @throws BrokerUnavailableException if RabbitMQ is unreachable
     * @throws LogicException             if no transport is set up for the exchange of the entry
     */
    public function send(OutboxEntry $entry): void
    {
        $sender = $this->senders[$entry->getExchange()] ?? throw new LogicException(
            sprintf('Für den Exchange "%s" ist kein Transport eingerichtet.', $entry->getExchange()),
        );
        $stamp = new AmqpStamp($entry->getEventType(), AMQP_NOPARAM, AmqpProperties::of(
            $entry->getId()->toRfc4122(),
            $entry->getEventType(),
            $this->appId,
            $this->clock->now()->getTimestamp(),
        ));

        try {
            $sender->send(new Envelope(new RawMessage($entry->getPayload()), [$stamp]));
        } catch (TransportException $exception) {
            throw self::isBrokerUnavailable($exception)
                ? new BrokerUnavailableException($exception)
                : new MessageRejectedException($exception);
        }
    }

    /**
     * Tells an unreachable broker apart from a rejected or unconfirmed message. The broker is unreachable when
     * connecting fails or the connection breaks: the amqp extension reports this as AMQPConnectionException or as
     * AMQPException without a more specific type. Rejections are failures on the channel and at the exchange, the
     * missing confirmation (AMQPQueueException while waiting for the confirmation) and the negative acknowledgement,
     * which the transport reports as TransportException without an AMQP failure.
     *
     * @param TransportException $exception failure of the transport
     *
     * @return bool true if RabbitMQ is unreachable
     */
    private static function isBrokerUnavailable(TransportException $exception): bool
    {
        $causes = self::causes($exception);

        foreach ($causes as $cause) {
            if ($cause instanceof AMQPConnectionException) {
                return true;
            }
        }

        foreach ($causes as $cause) {
            if (
                $cause instanceof AMQPChannelException
                || $cause instanceof AMQPExchangeException
                || $cause instanceof AMQPQueueException
            ) {
                return false;
            }
        }

        foreach ($causes as $cause) {
            if ($cause instanceof AMQPException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the failure and all failures that caused it.
     *
     * @param Throwable $exception failure of the transport
     *
     * @return list<Throwable> the failure and its causes, from the outermost to the innermost
     */
    private static function causes(Throwable $exception): array
    {
        $causes = [];

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $causes[] = $cause;
        }

        return $causes;
    }
}
