<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use ACAT\MessageBundle\Outbox\Entity\OutboxStatus;

/**
 * Zugriff auf die Tabelle outbox_entries über Doctrine. Jede Änderung wird sofort geschrieben, damit ein
 * zugestellter Eintrag unmittelbar nach bestätigter Zustellung gelöscht ist.
 */
final readonly class DoctrineOutboxEntryStore implements OutboxEntryStore
{
    /**
     * Erzeugt den Zugriff.
     *
     * @param EntityManagerInterface $entityManager EntityManager von Doctrine
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Liefert alle Einträge, ausstehende wie fehlgeschlagene, in der Reihenfolge der Zustellung.
     *
     * @return list<OutboxEntry> die Einträge, sortiert nach created_at, bei Gleichstand nach id
     */
    public function findAllOrdered(): array
    {
        return $this->entityManager
            ->getRepository(OutboxEntry::class)
            ->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Speichert Versuchszähler und Status eines Eintrags sofort.
     *
     * @param OutboxEntry $entry geänderter Eintrag
     *
     * @return void
     */
    public function save(OutboxEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    /**
     * Löscht einen Eintrag sofort, unmittelbar nach bestätigter Zustellung.
     *
     * @param OutboxEntry $entry zugestellter Eintrag
     *
     * @return void
     */
    public function remove(OutboxEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    /**
     * Setzt fehlgeschlagene Einträge in einem UPDATE auf pending und den Versuchszähler auf 0.
     *
     * @param Uuid|null $id UUID eines einzelnen Eintrags oder null für alle fehlgeschlagenen Einträge
     *
     * @return int Anzahl der wieder angestoßenen Einträge
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
