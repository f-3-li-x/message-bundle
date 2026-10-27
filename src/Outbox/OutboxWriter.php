<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use JsonException;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use F3lix\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Legt Events als ausstehende Einträge in der Tabelle outbox_entries ab. Der Writer schreibt nicht selbst
 * (kein flush): Der Eintrag wird mit der Transaktion des Service geschrieben, in der auch die auslösende
 * Zustandsänderung liegt.
 */
final readonly class OutboxWriter
{
    /** Kodierung des Payloads; Schrägstriche und Umlaute bleiben lesbar */
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Erzeugt den Writer.
     *
     * @param EntityManagerInterface $entityManager EntityManager von Doctrine, dessen Transaktion schreibt
     * @param ExchangeResolver       $exchanges     Zuordnung des Exchange je Event
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExchangeResolver $exchanges,
    ) {
    }

    /**
     * Legt ein Event als ausstehenden Eintrag ab. Der Eintrag erhält eine UUID v7, die zugleich message_id der
     * Nachricht ist; created_at ist der Zeitpunkt aus dieser UUID. Ein leeres Objekt steht im Payload als
     * stdClass, da ein leeres Array als [] kodiert würde.
     *
     * @param string               $eventType   Event-Name, zugleich Routing-Key, z. B. person.updated
     * @param string               $aggregateId Wert des Feldes id aus dem Payload
     * @param array<string, mixed> $payload     Payload des Events in der Feldreihenfolge, die beim Empfänger ankommt
     *
     * @return OutboxEntry der abgelegte Eintrag
     *
     * @throws JsonException wenn sich das Payload nicht als JSON kodieren lässt
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
