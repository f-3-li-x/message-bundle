<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Support;

use AMQPExchange;
use AMQPException;
use LogicException;
use F3lix\MessageBundle\Logging\AmqpExchangeFactoryInterface;

/**
 * Test-Double für den Verbindungsaufbau des AmqpLogHandler: liefert der Reihe nach die hinterlegten Exchanges
 * oder wirft die hinterlegten Fehler; das letzte Ergebnis gilt für alle weiteren Versuche.
 */
final class ScriptedAmqpExchangeFactory implements AmqpExchangeFactoryInterface
{
    /** @var list<AMQPExchange|AMQPException> */
    private array $results;

    private int $connectionAttempts = 0;

    /**
     * Hinterlegt die Ergebnisse der Verbindungsversuche.
     *
     * @param AMQPExchange|AMQPException ...$results Ergebnisse in der Reihenfolge der Versuche
     */
    public function __construct(AMQPExchange|AMQPException ...$results)
    {
        $this->results = array_values($results);
    }

    /**
     * Liefert das nächste Ergebnis.
     *
     * @return AMQPExchange der hinterlegte Exchange
     *
     * @throws AMQPException der hinterlegte Fehler
     */
    public function create(): AMQPExchange
    {
        if ($this->results === []) {
            throw new LogicException('Für den Verbindungsaufbau ist kein Ergebnis hinterlegt.');
        }

        $result = $this->results[min($this->connectionAttempts, count($this->results) - 1)];
        ++$this->connectionAttempts;

        if ($result instanceof AMQPException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Liefert die Zahl der Verbindungsversuche.
     *
     * @return int die Versuche
     */
    public function connectionAttempts(): int
    {
        return $this->connectionAttempts;
    }
}
