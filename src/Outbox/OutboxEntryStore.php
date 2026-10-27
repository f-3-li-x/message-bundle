<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Access of the outbox publisher and the console commands to the entries of the table outbox_entries.
 */
interface OutboxEntryStore
{
    /**
     * Returns all entries, pending and failed, in the order of delivery.
     *
     * @return list<OutboxEntry> the entries, ordered by created_at, then by id
     */
    public function findAllOrdered(): array;

    /**
     * Saves the attempt counter and status of an entry immediately.
     *
     * @param OutboxEntry $entry changed entry
     *
     * @return void
     */
    public function save(OutboxEntry $entry): void;

    /**
     * Deletes an entry immediately, right after its delivery is confirmed.
     *
     * @param OutboxEntry $entry delivered entry
     *
     * @return void
     */
    public function remove(OutboxEntry $entry): void;

    /**
     * Sets failed entries back to pending and the attempt counter to 0.
     *
     * @param Uuid|null $id UUID of a single entry, or null for all failed entries
     *
     * @return int number of entries retried
     */
    public function retryFailed(?Uuid $id = null): int;
}
