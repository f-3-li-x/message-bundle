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
 * Entry of the transactional outbox (table outbox_entries): an event that was written in the same transaction as
 * the state change that caused it and still has to be delivered to RabbitMQ. The payload is stored as text in a
 * LONGTEXT column so that field order and empty objects are preserved.
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
     * Creates a pending entry.
     *
     * @param Uuid              $id          UUID v7 of the entry, also the message_id of the message
     * @param string            $aggregateId value of the field id in the payload
     * @param string            $eventType   event name, also the routing key of the message
     * @param string            $exchange    exchange the event is published to
     * @param string            $payload     payload of the event as JSON
     * @param DateTimeImmutable $createdAt   point in time the entry was created; converted to UTC with milliseconds
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
     * Counts a delivery attempt that the broker rejected or did not confirm. With the tenth counted failed attempt,
     * the entry gets the status failed.
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
     * Retries a failed entry: status pending, attempt counter 0.
     *
     * @return void
     */
    public function retry(): void
    {
        $this->status = OutboxStatus::Pending;
        $this->attempts = 0;
    }

    /**
     * Checks whether the entry has the status failed and is no longer delivered.
     *
     * @return bool true for the status failed
     */
    public function isFailed(): bool
    {
        return $this->status === OutboxStatus::Failed;
    }

    /**
     * Returns the UUID of the entry.
     *
     * @return Uuid the UUID v7, unchanged on redelivery
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the value of the field id in the payload.
     *
     * @return string the id that determines the order of delivery
     */
    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    /**
     * Returns the event name.
     *
     * @return string the event name, also the routing key, e.g. person.updated
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * Returns the exchange the event is published to.
     *
     * @return string the name of the exchange
     */
    public function getExchange(): string
    {
        return $this->exchange;
    }

    /**
     * Returns the payload of the event.
     *
     * @return string the payload as JSON, as it is sent to RabbitMQ as the body
     */
    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * Returns the status.
     *
     * @return OutboxStatus pending or failed
     */
    public function getStatus(): OutboxStatus
    {
        return $this->status;
    }

    /**
     * Returns the attempt counter.
     *
     * @return int number of delivery attempts the broker rejected or did not confirm
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Returns the point in time the entry was created.
     *
     * @return DateTimeImmutable point in time in UTC with milliseconds
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
