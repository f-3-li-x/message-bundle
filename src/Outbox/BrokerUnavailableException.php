<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Throwable;
use RuntimeException;

/**
 * RabbitMQ is unreachable: connecting failed or the connection broke. Does not count as a delivery attempt; the
 * run of the outbox publisher aborts.
 */
final class BrokerUnavailableException extends RuntimeException
{
    /**
     * Creates the exception.
     *
     * @param Throwable|null $previous technical failure that caused it
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('RabbitMQ ist nicht erreichbar.', 0, $previous);
    }
}
