<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

/**
 * Ergebnis eines Laufs des Outbox-Publishers.
 */
final readonly class PublishResult
{
    /**
     * Hält das Ergebnis eines Laufs fest.
     *
     * @param int  $delivered Anzahl der zugestellten und gelöschten Einträge
     * @param int  $rejected  Anzahl der gezählten Fehlversuche (Nachricht abgelehnt oder nicht bestätigt)
     * @param int  $skipped   Anzahl der ausstehenden Einträge, die wegen eines früheren, nicht zugestellten oder
     *                        fehlgeschlagenen Eintrags derselben aggregate_id übersprungen wurden
     * @param bool $aborted   true, wenn der Lauf abgebrochen wurde, weil RabbitMQ nicht erreichbar ist
     */
    public function __construct(
        public int $delivered,
        public int $rejected,
        public int $skipped,
        public bool $aborted,
    ) {
    }
}
