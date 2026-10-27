<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Support;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Creates outbox entries for tests.
 */
final class OutboxEntries
{
    /**
     * Creates a pending entry.
     *
     * @param string $id          UUID of the entry
     * @param string $aggregateId value of the field id in the payload
     * @param string $createdAt   point in time of creation
     * @param string $eventType   event name
     * @param string $exchange    exchange
     *
     * @return OutboxEntry the entry
     */
    public static function pending(
        string $id,
        string $aggregateId = '018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a',
        string $createdAt = '2026-07-21T10:00:00.000Z',
        string $eventType = 'person.updated',
        string $exchange = 'domain-events',
    ): OutboxEntry {
        return new OutboxEntry(
            Uuid::fromString($id),
            $aggregateId,
            $eventType,
            $exchange,
            sprintf('{"id":"%s","actorId":"f47c1c2e","timestamp":"2026-07-21T10:00:00.000Z"}', $aggregateId),
            new DateTimeImmutable($createdAt),
        );
    }

    /**
     * Creates an entry with the status failed.
     *
     * @param string $id          UUID of the entry
     * @param string $aggregateId value of the field id in the payload
     * @param string $createdAt   point in time of creation
     *
     * @return OutboxEntry the entry
     */
    public static function failed(
        string $id,
        string $aggregateId = '018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a',
        string $createdAt = '2026-07-21T10:00:00.000Z',
    ): OutboxEntry {
        $entry = self::pending($id, $aggregateId, $createdAt);

        for ($attempt = 0; $attempt < OutboxEntry::MAX_ATTEMPTS; ++$attempt) {
            $entry->recordFailedAttempt();
        }

        return $entry;
    }
}
