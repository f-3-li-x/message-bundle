<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Logging;

use AMQPExchange;
use AMQPException;

/**
 * Connects to RabbitMQ and returns the exchange the log events go to. Tests replace the connection through this
 * interface.
 */
interface AmqpExchangeFactoryInterface
{
    /**
     * Opens a new connection and returns the exchange of the service on a new channel.
     *
     * @return AMQPExchange the exchange on an open connection
     *
     * @throws AMQPException if the connection cannot be established
     */
    public function create(): AMQPExchange;
}
