<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Logging;

use AMQPExchange;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Monolog\Handler\AmqpHandler;
use F3lix\MessageBundle\Message\AmqpProperties;

/**
 * Nativer AmqpHandler von Monolog für die Log-Events des Service: Der Routing-Key ist unabhängig vom Channel
 * {Log-Level}.<service> in Kleinbuchstaben, und jede Nachricht trägt die AMQP-Properties aus AmqpProperties.
 *
 * @internal wird ausschließlich von AmqpLogHandler verwendet
 */
final class LogEventAmqpHandler extends AmqpHandler
{
    /**
     * Erzeugt den Handler auf einem offenen Exchange.
     *
     * @param AMQPExchange   $exchange Exchange des Service auf einer offenen Verbindung
     * @param string         $service  Name des Service
     * @param ClockInterface $clock    Uhr für die AMQP-Property timestamp
     */
    public function __construct(
        AMQPExchange $exchange,
        private readonly string $service,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($exchange);
    }

    /**
     * Veröffentlicht einen Log-Eintrag mit message_id (eine neue UUID v7 je Log-Event), type (der Routing-Key),
     * app_id, content_type, timestamp und delivery_mode.
     *
     * @param LogRecord $record formatierter Log-Eintrag
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
     * Liefert den Routing-Key eines Log-Eintrags.
     *
     * @param LogRecord $record Log-Eintrag
     *
     * @return string der Routing-Key, z. B. error.person-service
     */
    protected function getRoutingKey(LogRecord $record): string
    {
        return strtolower($record->level->name) . '.' . $this->service;
    }
}
