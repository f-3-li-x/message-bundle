<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Connection;

use SensitiveParameter;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Erzeugt den AMQP-Transport für den DSN rabbitmq-env://: Die Verbindungsdaten kommen aus den Variablen
 * RABBITMQ_* statt aus dem DSN, die Optionen des Transports (Exchange, Queues, auto_setup, ...) aus seiner
 * Konfiguration.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final readonly class RabbitMqTransportFactory implements TransportFactoryInterface
{
    public const string DSN = 'rabbitmq-env://';

    /** Zeitlimit für den Verbindungsaufbau der Transports in Sekunden */
    private const float CONNECT_TIMEOUT = 5.0;

    /**
     * Erzeugt die Factory.
     *
     * @param RabbitMqConfig                                $config Verbindungsdaten aus RABBITMQ_*
     * @param TransportFactoryInterface<TransportInterface> $amqp   Factory des AMQP-Transports von Symfony Messenger
     */
    public function __construct(
        private RabbitMqConfig $config,
        private TransportFactoryInterface $amqp,
    ) {
    }

    /**
     * Erzeugt den AMQP-Transport mit den Verbindungsdaten aus RABBITMQ_*. Statt des DSN wird nur das Schema
     * weitergegeben, amqps:// bei verschlüsselter Verbindung, sonst amqp://; eine Option des Transports hat
     * Vorrang vor den gleichnamigen Verbindungsdaten.
     *
     * @param string               $dsn        rabbitmq-env://
     * @param array<string, mixed> $options    Optionen des Transports
     * @param SerializerInterface  $serializer Serializer des Transports
     *
     * @return TransportInterface der AMQP-Transport
     */
    public function createTransport(
        #[SensitiveParameter]
        string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        return $this->amqp->createTransport(
            $this->config->isSecure() ? 'amqps://' : 'amqp://',
            $options + $this->config->connectionOptions(self::CONNECT_TIMEOUT),
            $serializer,
        );
    }

    /**
     * Prüft, ob der DSN zu dieser Factory gehört.
     *
     * @param string               $dsn     DSN des Transports
     * @param array<string, mixed> $options Optionen des Transports
     *
     * @return bool true bei rabbitmq-env://
     */
    public function supports(
        #[SensitiveParameter]
        string $dsn,
        array $options,
    ): bool {
        return $dsn === self::DSN;
    }
}
