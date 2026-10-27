<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Logging;

use AMQPChannel;
use AMQPExchange;
use AMQPException;
use AMQPConnection;
use ACAT\MessageBundle\Connection\RabbitMqConfig;

/**
 * Connects to RabbitMQ via the amqp extension and returns the exchange of the service the log events go to. The
 * exchange is not declared; the broker topology is maintained outside the service.
 */
final readonly class AmqpExchangeFactory implements AmqpExchangeFactoryInterface
{
    /** Timeout for connecting and sending in seconds, so that an unreachable broker holds nothing up */
    public const float TIMEOUT = 3.0;

    /**
     * Creates the factory.
     *
     * @param RabbitMqConfig $config       connection data from RABBITMQ_*
     * @param string         $exchangeName name of the exchange for the log events, the name of the service
     */
    public function __construct(
        private RabbitMqConfig $config,
        private string $exchangeName,
    ) {
    }

    /**
     * Opens a new connection and returns the exchange of the service on a new channel. With TLS, RabbitMqConfig adds
     * the CA certificates; without them, no encrypted connection would be established.
     *
     * @return AMQPExchange the exchange on an open connection
     *
     * @throws AMQPException if the connection cannot be established
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
