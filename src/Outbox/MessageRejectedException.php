<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use Throwable;
use RuntimeException;

/**
 * Der Broker hat eine einzelne Nachricht abgelehnt oder nicht bestätigt (Publisher Confirms). Zählt als
 * Zustellversuch des Outbox-Eintrags.
 */
final class MessageRejectedException extends RuntimeException
{
    /**
     * Erzeugt die Exception.
     *
     * @param Throwable|null $previous auslösender technischer Fehler
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Der Broker hat die Nachricht abgelehnt oder nicht bestätigt.', 0, $previous);
    }
}
