<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use Throwable;
use RuntimeException;

/**
 * RabbitMQ ist nicht erreichbar: Der Verbindungsaufbau ist gescheitert oder die Verbindung ist abgebrochen.
 * Zählt nicht als Zustellversuch; der Lauf des Outbox-Publishers bricht ab.
 */
final class BrokerUnavailableException extends RuntimeException
{
    /**
     * Erzeugt die Exception.
     *
     * @param Throwable|null $previous auslösender technischer Fehler
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('RabbitMQ ist nicht erreichbar.', 0, $previous);
    }
}
