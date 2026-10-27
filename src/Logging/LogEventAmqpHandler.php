<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Logging;

use AMQPExchange;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Monolog\Handler\AmqpHandler;
use ACAT\MessageBundle\Message\AmqpProperties;

/**
 * Native AmqpHandler of Monolog for the log events of the service: the routing key is {log level}.<service> in lower
 * case regardless of the channel, and every message carries the AMQP properties from AmqpProperties.
 *
 * @internal used by AmqpLogHandler only
 */
final class LogEventAmqpHandler extends AmqpHandler
{
    /**
     * Creates the handler on an open exchange.
     *
     * @param AMQPExchange   $exchange exchange of the service on an open connection
     * @param string         $service  name of the service
     * @param ClockInterface $clock    clock for the AMQP property timestamp
     */
    public function __construct(
        AMQPExchange $exchange,
        private readonly string $service,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($exchange);
    }

    /**
     * Publishes a log record with message_id (a new UUID v7 per log event), type (the routing key), app_id,
     * content_type, timestamp and delivery_mode.
     *
     * @param LogRecord $record formatted log record
     *
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        $this->setExtraAttributes(AmqpProperties::of(
            Uuid::v7()->toRfc4122(),
            $this->getRoutingKey($record),
            $this->service,
            $this->clock->now()->getTimestamp(),
        ));

        parent::write($record);
    }

    /**
     * Returns the routing key of a log record.
     *
     * @param LogRecord $record log record
     *
     * @return string the routing key, e.g. error.person-service
     */
    protected function getRoutingKey(LogRecord $record): string
    {
        return strtolower($record->level->name) . '.' . $this->service;
    }
}
