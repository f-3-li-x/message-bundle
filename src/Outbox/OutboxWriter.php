<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use JsonException;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Stores events as pending entries in the table outbox_entries. The writer does not write by itself (no flush): the
 * entry is written with the service's transaction that also contains the state change that caused it.
 */
final readonly class OutboxWriter
{
    /** Encoding of the payload; slashes and non-ASCII characters stay readable */
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Creates the writer.
     *
     * @param EntityManagerInterface $entityManager EntityManager of Doctrine whose transaction writes the entry
     * @param ExchangeResolver       $exchanges     assignment of the exchange per event
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExchangeResolver $exchanges,
    ) {
    }

    /**
     * Stores an event as a pending entry. The entry gets a UUID v7 that is also the message_id of the message;
     * created_at is the point in time from this UUID. An empty object in the payload must be a stdClass, as an empty
     * array would be encoded as [].
     *
     * @param string               $eventType   event name, also the routing key, e.g. person.updated
     * @param string               $aggregateId value of the field id in the payload
     * @param array<string, mixed> $payload     payload of the event in the field order the receiver gets
     *
     * @return OutboxEntry the stored entry
     *
     * @throws JsonException if the payload cannot be encoded as JSON
     */
    public function append(string $eventType, string $aggregateId, array $payload): OutboxEntry
    {
        $id = Uuid::v7();
        $entry = new OutboxEntry(
            $id,
            $aggregateId,
            $eventType,
            $this->exchanges->exchangeFor($eventType),
            json_encode($payload, self::JSON_FLAGS),
            $id->getDateTime(),
        );

        $this->entityManager->persist($entry);

        return $entry;
    }
}
