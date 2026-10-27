<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Logging;

use AMQPChannel;
use AMQPExchange;
use AMQPException;
use AMQPConnection;
use F3lix\MessageBundle\Connection\RabbitMqConfig;

/**
 * Baut über die Extension amqp die Verbindung zu RabbitMQ auf und liefert den Exchange des Service, an den die
 * Log-Events gehen. Der Exchange wird nicht deklariert; die Topologie am Broker wird außerhalb des Service gepflegt.
 */
final readonly class AmqpExchangeFactory implements AmqpExchangeFactoryInterface
{
    /** Zeitlimit für Verbindungsaufbau und Senden in Sekunden, damit ein nicht erreichbarer Broker nichts aufhält */
    public const float TIMEOUT = 3.0;

    /**
     * Erzeugt die Factory.
     *
     * @param RabbitMqConfig $config       Verbindungsdaten aus RABBITMQ_*
     * @param string         $exchangeName Name des Exchange für die Log-Events, der Name des Service
     */
    public function __construct(
        private RabbitMqConfig $config,
        private string $exchangeName,
    ) {
    }

    /**
     * Baut eine neue Verbindung auf und liefert den Exchange des Service auf einem neuen Kanal. Bei TLS gibt
     * RabbitMqConfig die CA-Zertifikate mit; ohne sie entstünde keine verschlüsselte Verbindung.
     *
     * @return AMQPExchange der Exchange auf einer offenen Verbindung
     *
     * @throws AMQPException wenn die Verbindung nicht aufgebaut werden kann
     */
    public function create(): AMQPExchange
    {
        $connection = new AMQPConnection(
            $this->config->connectionOptions(self::TIMEOUT) + ['write_timeout' => self::TIMEOUT],
        );
        $connection->connect();

        $exchange = new AMQPExchange(new AMQPChannel($connection));
        $exchange->setName($this->exchangeName);

        return $exchange;
    }
}
