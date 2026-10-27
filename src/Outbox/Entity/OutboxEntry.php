<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox\Entity;

use DateTimeZone;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use ACAT\MessageBundle\Persistence\UtcDateTimeMsType;

/**
 * Eintrag der Transactional Outbox (Tabelle outbox_entries): ein Event, das in derselben Transaktion wie
 * die auslösende Zustandsänderung geschrieben wurde und noch an RabbitMQ zuzustellen ist. Das Payload liegt als
 * Text in einer Spalte vom Typ LONGTEXT, damit Feldreihenfolge und leere Objekte erhalten bleiben.
 */
#[ORM\Entity]
#[ORM\Table(name: 'outbox_entries')]
#[ORM\Index(name: 'idx_outbox_entries_status_created_at', columns: ['status', 'created_at'])]
class OutboxEntry
{
    public const int MAX_ATTEMPTS = 10;

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'aggregate_id', type: Types::STRING, length: 255)]
    private string $aggregateId;

    #[ORM\Column(name: 'event_type', type: Types::STRING, length: 255)]
    private string $eventType;

    #[ORM\Column(name: 'exchange', type: Types::STRING, length: 255)]
    private string $exchange;

    #[ORM\Column(name: 'payload', type: Types::TEXT)]
    private string $payload;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, enumType: OutboxStatus::class)]
    private OutboxStatus $status = OutboxStatus::Pending;

    #[ORM\Column(name: 'attempts', type: Types::INTEGER)]
    private int $attempts = 0;

    #[ORM\Column(
        name: 'created_at',
        type: UtcDateTimeMsType::NAME,
        columnDefinition: UtcDateTimeMsType::COLUMN_DEFINITION,
    )]
    private DateTimeImmutable $createdAt;

    /**
     * Legt einen ausstehenden Eintrag an.
     *
     * @param Uuid              $id          UUID v7 des Eintrags, zugleich message_id der Nachricht
     * @param string            $aggregateId Wert des Feldes id aus dem Payload
     * @param string            $eventType   Event-Name, zugleich Routing-Key der Nachricht
     * @param string            $exchange    Exchange, an den das Event veröffentlicht wird
     * @param string            $payload     Payload des Events als JSON
     * @param DateTimeImmutable $createdAt   Zeitpunkt, zu dem der Eintrag angelegt wurde; wird auf UTC mit
     *                                       Millisekunden gebracht
     */
    public function __construct(
        Uuid $id,
        string $aggregateId,
        string $eventType,
        string $exchange,
        string $payload,
        DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->aggregateId = $aggregateId;
        $this->eventType = $eventType;
        $this->exchange = $exchange;
        $this->payload = $payload;
        $this->createdAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.v',
            $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            new DateTimeZone('UTC'),
        ) ?: $createdAt;
    }

    /**
     * Zählt einen Zustellversuch, den der Broker abgelehnt oder nicht bestätigt hat. Mit dem zehnten gezählten
     * Fehlversuch erhält der Eintrag den Status failed.
     *
     * @return void
     */
    public function recordFailedAttempt(): void
    {
        ++$this->attempts;

        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->status = OutboxStatus::Failed;
        }
    }

    /**
     * Stößt einen fehlgeschlagenen Eintrag wieder an: Status pending, Versuchszähler 0.
     *
     * @return void
     */
    public function retry(): void
    {
        $this->status = OutboxStatus::Pending;
        $this->attempts = 0;
    }

    /**
     * Prüft, ob der Eintrag den Status failed hat und nicht weiter zugestellt wird.
     *
     * @return bool true beim Status failed
     */
    public function isFailed(): bool
    {
        return $this->status === OutboxStatus::Failed;
    }

    /**
     * Liefert die UUID des Eintrags.
     *
     * @return Uuid die UUID v7, bei erneuter Zustellung unverändert
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Liefert den Wert des Feldes id aus dem Payload.
     *
     * @return string die id, an der die Reihenfolge der Zustellung hängt
     */
    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    /**
     * Liefert den Event-Namen.
     *
     * @return string der Event-Name, zugleich Routing-Key, z. B. person.updated
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * Liefert den Exchange, an den das Event veröffentlicht wird.
     *
     * @return string der Name des Exchange
     */
    public function getExchange(): string
    {
        return $this->exchange;
    }

    /**
     * Liefert das Payload des Events.
     *
     * @return string das Payload als JSON, wie es als Body an RabbitMQ geht
     */
    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * Liefert den Status.
     *
     * @return OutboxStatus pending oder failed
     */
    public function getStatus(): OutboxStatus
    {
        return $this->status;
    }

    /**
     * Liefert den Versuchszähler.
     *
     * @return int Anzahl der vom Broker abgelehnten oder nicht bestätigten Zustellversuche
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Liefert den Zeitpunkt, zu dem der Eintrag angelegt wurde.
     *
     * @return DateTimeImmutable Zeitpunkt in UTC mit Millisekunden
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
