<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Support;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Erzeugt Outbox-Einträge für Tests.
 */
final class OutboxEntries
{
    /**
     * Erzeugt einen ausstehenden Eintrag.
     *
     * @param string $id          UUID des Eintrags
     * @param string $aggregateId Wert des Feldes id aus dem Payload
     * @param string $createdAt   Erstellungszeitpunkt
     * @param string $eventType   Event-Name
     * @param string $exchange    Exchange
     *
     * @return OutboxEntry der Eintrag
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
     * Erzeugt einen Eintrag mit Status failed.
     *
     * @param string $id          UUID des Eintrags
     * @param string $aggregateId Wert des Feldes id aus dem Payload
     * @param string $createdAt   Erstellungszeitpunkt
     *
     * @return OutboxEntry der Eintrag
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
