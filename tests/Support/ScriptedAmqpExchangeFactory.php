<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Support;

use AMQPExchange;
use AMQPException;
use LogicException;
use ACAT\MessageBundle\Logging\AmqpExchangeFactoryInterface;

/**
 * Test double for the connection of the AmqpLogHandler: returns the given exchanges or throws the given failures
 * in turn; the last result applies to all further attempts.
 */
final class ScriptedAmqpExchangeFactory implements AmqpExchangeFactoryInterface
{
    /** @var list<AMQPExchange|AMQPException> */
    private array $results;

    private int $connectionAttempts = 0;

    /**
     * Sets the results of the connection attempts.
     *
     * @param AMQPExchange|AMQPException ...$results results in the order of the attempts
     */
    public function __construct(AMQPExchange|AMQPException ...$results)
    {
        $this->results = array_values($results);
    }

    /**
     * Returns the next result.
     *
     * @return AMQPExchange the given exchange
     *
     * @throws AMQPException the given failure
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
     * Returns the number of connection attempts.
     *
     * @return int the attempts
     */
    public function connectionAttempts(): int
    {
        return $this->connectionAttempts;
    }
}
