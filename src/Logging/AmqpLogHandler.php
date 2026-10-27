<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Logging;

use Throwable;
use Monolog\Level;
use AMQPException;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Monolog\Handler\AbstractHandler;

/**
 * Passes the log records on to RabbitMQ as events. Wraps the native AmqpHandler of Monolog: the connection is opened
 * with the first log record only, and no failure when connecting or sending reaches the caller. Logging to RabbitMQ
 * never makes a request or the processing of a message fail; the file log stays complete.
 */
final class AmqpLogHandler extends AbstractHandler
{
    /** Seconds without a new connection attempt after a failure */
    public const int RETRY_PAUSE = 60;

    private ?LogEventAmqpHandler $handler = null;
    private int $lastActivity = 0;
    private int $pausedUntil = 0;

    /**
     * Creates the handler without connecting.
     *
     * @param AmqpExchangeFactoryInterface $exchangeFactory opens the connection and returns the exchange
     * @param string                       $service         name of the service for routing key and app_id
     * @param int                          $heartbeat       heartbeat interval of the connection in seconds, 0 for none
     * @param ClockInterface               $clock           clock for the pause, idle time and the AMQP property timestamp
     * @param bool                         $enabled         false disables the handler, e.g. in the test environment
     * @param Level                        $level           lowest level that is passed on
     * @param bool                         $bubble          whether a passed record goes on to the following handlers
     */
    public function __construct(
        private readonly AmqpExchangeFactoryInterface $exchangeFactory,
        private readonly string $service,
        private readonly int $heartbeat,
        private readonly ClockInterface $clock,
        private readonly bool $enabled = true,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Checks whether the handler passes the record on.
     *
     * @param LogRecord $record log record
     *
     * @return bool false if the handler is disabled or the level of the record is too low
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->enabled && parent::isHandling($record);
    }

    /**
     * Passes the record on to RabbitMQ and catches every failure. If an open connection fails, one new connection is
     * opened for the record; after the last failed attempt no connection is opened for 60 seconds.
     *
     * @param LogRecord $record log record
     *
     * @return bool false if the record is not passed on or goes on to the following handlers
     */
    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $attempts = $this->handler === null ? 1 : 2;

        while ($attempts-- > 0) {
            try {
                $this->publish($record);

                break;
            } catch (Throwable) {
                $this->handler = null;

                if ($attempts === 0) {
                    $this->pausedUntil = $this->now() + self::RETRY_PAUSE;
                }
            }
        }

        return !$this->bubble;
    }

    /**
     * Drops the open connection.
     *
     * @return void
     */
    public function close(): void
    {
        $this->handler = null;
    }

    /**
     * Passes the record on to the native handler, connecting first if no connection is open. A connection that was
     * idle for a heartbeat interval is replaced: the amqp extension sends heartbeats only while it waits for the
     * broker, and the broker closes an idle connection. During the pause after a failure, the record is dropped
     * without a connection attempt.
     *
     * @param LogRecord $record log record
     *
     * @return void
     *
     * @throws AMQPException if connecting or sending fails
     */
    private function publish(LogRecord $record): void
    {
        $now = $this->now();

        if ($this->handler !== null && $this->heartbeat > 0 && $now - $this->lastActivity >= $this->heartbeat) {
            $this->handler = null;
        }

        if ($this->handler === null) {
            if ($now < $this->pausedUntil) {
                return;
            }

            $this->handler = new LogEventAmqpHandler($this->exchangeFactory->create(), $this->service, $this->clock);
        }

        $this->handler->handle($record);
        $this->lastActivity = $now;
    }

    /**
     * Returns the current time.
     *
     * @return int Unix time in seconds
     */
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
