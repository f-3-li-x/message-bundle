<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use Symfony\Component\Uid\Uuid;
use F3lix\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Zugriff des Outbox-Publishers und der Konsolenkommandos auf die Einträge der Tabelle outbox_entries.
 */
interface OutboxEntryStore
{
    /**
     * Liefert alle Einträge, ausstehende wie fehlgeschlagene, in der Reihenfolge der Zustellung.
     *
     * @return list<OutboxEntry> die Einträge, sortiert nach created_at, bei Gleichstand nach id
     */
    public function findAllOrdered(): array;

    /**
     * Speichert Versuchszähler und Status eines Eintrags sofort.
     *
     * @param OutboxEntry $entry geänderter Eintrag
     *
     * @return void
     */
    public function save(OutboxEntry $entry): void;

    /**
     * Löscht einen Eintrag sofort, unmittelbar nach bestätigter Zustellung.
     *
     * @param OutboxEntry $entry zugestellter Eintrag
     *
     * @return void
     */
    public function remove(OutboxEntry $entry): void;

    /**
     * Setzt fehlgeschlagene Einträge auf pending und den Versuchszähler auf 0.
     *
     * @param Uuid|null $id UUID eines einzelnen Eintrags oder null für alle fehlgeschlagenen Einträge
     *
     * @return int Anzahl der wieder angestoßenen Einträge
     */
    public function retryFailed(?Uuid $id = null): int;
}
