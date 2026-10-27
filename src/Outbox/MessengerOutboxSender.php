<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use Throwable;
use AMQPException;
use LogicException;
use AMQPQueueException;
use AMQPChannelException;
use AMQPExchangeException;
use AMQPConnectionException;
use Psr\Clock\ClockInterface;
use F3lix\MessageBundle\Message\RawMessage;
use Symfony\Component\Messenger\Envelope;
use F3lix\MessageBundle\Message\AmqpProperties;
use F3lix\MessageBundle\Outbox\Entity\OutboxEntry;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Veröffentlicht Outbox-Einträge über die Transports von Symfony Messenger an RabbitMQ. Die Transports arbeiten
 * mit Publisher Confirms; kehrt der Transport ohne Fehler zurück, hat der Broker die Nachricht bestätigt.
 */
final readonly class MessengerOutboxSender implements OutboxSender
{
    /**
     * Erzeugt den Sender.
     *
     * @param array<string, SenderInterface> $senders Transport je Exchange, Schlüssel ist der Name des Exchange
     * @param string                         $appId   Name des Service, AMQP-Property app_id
     * @param ClockInterface                 $clock   Uhr für die AMQP-Property timestamp
     */
    public function __construct(
        private array $senders,
        private string $appId,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Veröffentlicht die Nachricht eines Outbox-Eintrags an dessen Exchange. Der Body ist das Payload; Routing-Key
     * und AMQP-Properties bildet AmqpProperties.
     *
     * @param OutboxEntry $entry zuzustellender Eintrag
     *
     * @return void
     *
     * @throws MessageRejectedException   wenn der Broker die Nachricht ablehnt oder nicht bestätigt
     * @throws BrokerUnavailableException wenn RabbitMQ nicht erreichbar ist
     * @throws LogicException             wenn für den Exchange des Eintrags kein Transport eingerichtet ist
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
     * Unterscheidet einen nicht erreichbaren Broker von einer abgelehnten oder nicht bestätigten Nachricht. Nicht
     * erreichbar ist der Broker, wenn der Verbindungsaufbau scheitert oder die Verbindung abbricht: Die Extension
     * amqp meldet das als AMQPConnectionException oder als AMQPException ohne nähere Art. Als Ablehnung gelten
     * Fehler auf dem Kanal und am Exchange, die ausbleibende Bestätigung (AMQPQueueException beim Warten auf die
     * Bestätigung) und die negative Bestätigung, die der Transport ohne AMQP-Fehler als TransportException meldet.
     *
     * @param TransportException $exception Fehler des Transports
     *
     * @return bool true, wenn RabbitMQ nicht erreichbar ist
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
     * Liefert den Fehler und alle Fehler, die ihn ausgelöst haben.
     *
     * @param Throwable $exception Fehler des Transports
     *
     * @return list<Throwable> der Fehler und seine Ursachen, vom äußersten zum innersten
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
