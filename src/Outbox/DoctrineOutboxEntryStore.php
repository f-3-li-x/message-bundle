<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use ACAT\MessageBundle\Outbox\Entity\OutboxStatus;

/**
 * Access to the table outbox_entries via Doctrine. Every change is written immediately so that a delivered entry is
 * deleted right after its delivery is confirmed.
 */
final readonly class DoctrineOutboxEntryStore implements OutboxEntryStore
{
    /**
     * Creates the store.
     *
     * @param EntityManagerInterface $entityManager EntityManager of Doctrine
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Returns all entries, pending and failed, in the order of delivery.
     *
     * @return list<OutboxEntry> the entries, ordered by created_at, then by id
     */
    public function findAllOrdered(): array
    {
        return $this->entityManager
            ->getRepository(OutboxEntry::class)
            ->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Saves the attempt counter and status of an entry immediately.
     *
     * @param OutboxEntry $entry changed entry
     *
     * @return void
     */
    public function save(OutboxEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    /**
     * Deletes an entry immediately, right after its delivery is confirmed.
     *
     * @param OutboxEntry $entry delivered entry
     *
     * @return void
     */
    public function remove(OutboxEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    /**
     * Sets failed entries back to pending and the attempt counter to 0 in one UPDATE.
     *
     * @param Uuid|null $id UUID of a single entry, or null for all failed entries
     *
     * @return int number of entries retried
     */
    public function retryFailed(?Uuid $id = null): int
    {
        $dql = 'UPDATE ' . OutboxEntry::class . ' entry SET entry.status = :pending, entry.attempts = 0'
            . ' WHERE entry.status = :failed';

        $query = $this->entityManager
            ->createQuery($id === null ? $dql : $dql . ' AND entry.id = :id')
            ->setParameter('pending', OutboxStatus::Pending->value)
            ->setParameter('failed', OutboxStatus::Failed->value);

        if ($id !== null) {
            $query->setParameter('id', $id, UuidType::NAME);
        }

        $count = $query->execute();

        return is_int($count) ? $count : 0;
    }
}
