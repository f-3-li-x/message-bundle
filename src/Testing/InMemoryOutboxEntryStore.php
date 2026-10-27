<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\OutboxEntryStore;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Test-Double für die Tabelle outbox_entries: hält die Einträge im Speicher.
 */
final class InMemoryOutboxEntryStore implements OutboxEntryStore
{
    /** @var array<string, OutboxEntry> Einträge, Schlüssel ist die UUID im Format RFC 4122 */
    private array $entries = [];

    /**
     * Erzeugt den Speicher mit vorhandenen Einträgen.
     *
     * @param OutboxEntry ...$entries vorhandene Einträge
     */
    public function __construct(OutboxEntry ...$entries)
    {
        foreach ($entries as $entry) {
            $this->add($entry);
        }
    }

    /**
     * Legt einen Eintrag ab.
     *
     * @param OutboxEntry $entry Eintrag
     *
     * @return void
     */
    public function add(OutboxEntry $entry): void
    {
        $this->entries[$entry->getId()->toRfc4122()] = $entry;
    }

    /**
     * Liefert alle Einträge, ausstehende wie fehlgeschlagene, in der Reihenfolge der Zustellung.
     *
     * @return list<OutboxEntry> die Einträge, sortiert nach created_at, bei Gleichstand nach id
     */
    public function findAllOrdered(): array
    {
        $entries = array_values($this->entries);

        usort($entries, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->getCreatedAt(), $a->getId()->toRfc4122()]
            <=> [$b->getCreatedAt(), $b->getId()->toRfc4122()]);

        return $entries;
    }

    /**
     * Speichert einen Eintrag; im Speicher ist er bereits geändert.
     *
     * @param OutboxEntry $entry geänderter Eintrag
     *
     * @return void
     */
    public function save(OutboxEntry $entry): void
    {
        $this->add($entry);
    }

    /**
     * Löscht einen Eintrag.
     *
     * @param OutboxEntry $entry zugestellter Eintrag
     *
     * @return void
     */
    public function remove(OutboxEntry $entry): void
    {
        unset($this->entries[$entry->getId()->toRfc4122()]);
    }

    /**
     * Setzt fehlgeschlagene Einträge auf pending und den Versuchszähler auf 0.
     *
     * @param Uuid|null $id UUID eines einzelnen Eintrags oder null für alle fehlgeschlagenen Einträge
     *
     * @return int Anzahl der wieder angestoßenen Einträge
     */
    public function retryFailed(?Uuid $id = null): int
    {
        $count = 0;

        foreach ($this->entries as $entry) {
            if ($entry->isFailed() && ($id === null || $entry->getId()->equals($id))) {
                $entry->retry();
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Liefert den Eintrag mit einer UUID.
     *
     * @param string $id UUID im Format RFC 4122
     *
     * @return OutboxEntry|null der Eintrag oder null, wenn er gelöscht oder nie abgelegt wurde
     */
    public function find(string $id): ?OutboxEntry
    {
        return $this->entries[$id] ?? null;
    }
}
