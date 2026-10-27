<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Logging;

use AMQPExchange;
use AMQPException;

/**
 * Baut die Verbindung zu RabbitMQ auf und liefert den Exchange, an den die Log-Events gehen. Tests ersetzen die
 * Verbindung über dieses Interface.
 */
interface AmqpExchangeFactoryInterface
{
    /**
     * Baut eine neue Verbindung auf und liefert den Exchange des Service auf einem neuen Kanal.
     *
     * @return AMQPExchange der Exchange auf einer offenen Verbindung
     *
     * @throws AMQPException wenn die Verbindung nicht aufgebaut werden kann
     */
    public function create(): AMQPExchange;
}
