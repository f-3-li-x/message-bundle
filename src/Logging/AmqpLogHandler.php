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
 * Gibt die Log-Einträge als Events an RabbitMQ weiter. Kapselt den nativen AmqpHandler von Monolog: Die
 * Verbindung entsteht erst mit dem ersten Log-Eintrag, und kein Fehler beim Verbindungsaufbau oder Senden erreicht
 * den Aufrufer. Logging an RabbitMQ lässt eine Anfrage oder die Verarbeitung einer Nachricht nie scheitern; das
 * Datei-Log bleibt vollständig.
 */
final class AmqpLogHandler extends AbstractHandler
{
    /** Sekunden ohne neuen Verbindungsaufbau nach einem Fehler */
    public const int RETRY_PAUSE = 60;

    private ?LogEventAmqpHandler $handler = null;
    private int $lastActivity = 0;
    private int $pausedUntil = 0;

    /**
     * Erzeugt den Handler ohne Verbindungsaufbau.
     *
     * @param AmqpExchangeFactoryInterface $exchangeFactory Aufbau der Verbindung und des Exchange
     * @param string                       $service         Name des Service für Routing-Key und app_id
     * @param int                          $heartbeat       Heartbeat-Intervall der Verbindung in Sekunden, 0 ohne
     * @param ClockInterface               $clock           Uhr für Pause, Leerlauf und AMQP-Property timestamp
     * @param bool                         $enabled         false schaltet den Handler ab, z. B. in der Umgebung test
     * @param Level                        $level           niedrigstes Level, das weitergegeben wird
     * @param bool                         $bubble          ob ein weitergegebener Eintrag an die folgenden Handler geht
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
     * Prüft, ob der Handler den Eintrag weitergibt.
     *
     * @param LogRecord $record Log-Eintrag
     *
     * @return bool false, wenn der Handler abgeschaltet ist oder das Level des Eintrags zu niedrig ist
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->enabled && parent::isHandling($record);
    }

    /**
     * Gibt den Eintrag an RabbitMQ weiter und fängt jeden Fehler ab. Scheitert eine offene Verbindung, wird für den
     * Eintrag einmal eine neue aufgebaut; nach dem letzten Fehlversuch wird 60 Sekunden lang keine Verbindung
     * aufgebaut.
     *
     * @param LogRecord $record Log-Eintrag
     *
     * @return bool false, wenn der Eintrag nicht weitergegeben wird oder an die folgenden Handler geht
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
     * Verwirft die offene Verbindung.
     *
     * @return void
     */
    public function close(): void
    {
        $this->handler = null;
    }

    /**
     * Gibt den Eintrag an den nativen Handler weiter und baut vorher die Verbindung auf, falls keine offen ist. Eine
     * Verbindung, die ein Heartbeat-Intervall lang ungenutzt war, wird ersetzt: Die Extension amqp sendet Heartbeats
     * nur, während sie auf den Broker wartet, und der Broker schließt eine ungenutzte Verbindung. Während der Pause
     * nach einem Fehler wird der Eintrag ohne Verbindungsaufbau verworfen.
     *
     * @param LogRecord $record Log-Eintrag
     *
     * @return void
     *
     * @throws AMQPException wenn Verbindungsaufbau oder Senden scheitert
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
     * Liefert die aktuelle Zeit.
     *
     * @return int Unix-Zeit in Sekunden
     */
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
