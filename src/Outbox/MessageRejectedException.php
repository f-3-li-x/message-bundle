<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Throwable;
use RuntimeException;

/**
 * The broker rejected a single message or did not confirm it (publisher confirms). Counts as a delivery attempt
 * of the outbox entry.
 */
final class MessageRejectedException extends RuntimeException
{
    /**
     * Creates the exception.
     *
     * @param Throwable|null $previous technical failure that caused it
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Der Broker hat die Nachricht abgelehnt oder nicht bestätigt.', 0, $previous);
    }
}
