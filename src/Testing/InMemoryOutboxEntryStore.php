<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\OutboxEntryStore;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Test double for the table outbox_entries: keeps the entries in memory.
 */
final class InMemoryOutboxEntryStore implements OutboxEntryStore
{
    /** @var array<string, OutboxEntry> entries, keyed by the UUID in RFC 4122 format */
    private array $entries = [];

    /**
     * Creates the store with existing entries.
     *
     * @param OutboxEntry ...$entries existing entries
     */
    public function __construct(OutboxEntry ...$entries)
    {
        foreach ($entries as $entry) {
            $this->add($entry);
        }
    }

    /**
     * Adds an entry.
     *
     * @param OutboxEntry $entry entry
     *
     * @return void
     */
    public function add(OutboxEntry $entry): void
    {
        $this->entries[$entry->getId()->toRfc4122()] = $entry;
    }

    /**
     * Returns all entries, pending and failed, in the order of delivery.
     *
     * @return list<OutboxEntry> the entries, ordered by created_at, then by id
     */
    public function findAllOrdered(): array
    {
        $entries = array_values($this->entries);

        usort($entries, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->getCreatedAt(), $a->getId()->toRfc4122()]
            <=> [$b->getCreatedAt(), $b->getId()->toRfc4122()]);

        return $entries;
    }

    /**
     * Saves an entry; in memory it is already changed.
     *
     * @param OutboxEntry $entry changed entry
     *
     * @return void
     */
    public function save(OutboxEntry $entry): void
    {
        $this->add($entry);
    }

    /**
     * Deletes an entry.
     *
     * @param OutboxEntry $entry delivered entry
     *
     * @return void
     */
    public function remove(OutboxEntry $entry): void
    {
        unset($this->entries[$entry->getId()->toRfc4122()]);
    }

    /**
     * Sets failed entries back to pending and the attempt counter to 0.
     *
     * @param Uuid|null $id UUID of a single entry, or null for all failed entries
     *
     * @return int number of entries retried
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
     * Returns the entry with a UUID.
     *
     * @param string $id UUID in RFC 4122 format
     *
     * @return OutboxEntry|null the entry, or null if it was deleted or never added
     */
    public function find(string $id): ?OutboxEntry
    {
        return $this->entries[$id] ?? null;
    }
}
