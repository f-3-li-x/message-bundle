<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use ACAT\MessageBundle\Outbox\OutboxSender;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use ACAT\MessageBundle\Outbox\MessageRejectedException;
use ACAT\MessageBundle\Outbox\BrokerUnavailableException;

/**
 * Test double for the handover to RabbitMQ: simulates rejected messages and an unreachable broker, and optionally
 * passes confirmed messages on to a real sender, e.g. MessengerOutboxSender with in-memory transports.
 */
final class ScriptedOutboxSender implements OutboxSender
{
    /** @var array<string, true> UUIDs of the entries whose message is rejected */
    private array $rejectedIds = [];

    /** @var list<string> UUIDs of the entries in the order of the delivery attempts */
    private array $attemptedIds = [];

    /** @var list<OutboxEntry> confirmed entries in the order of delivery */
    private array $confirmed = [];

    private bool $rejectEverything = false;

    private ?int $unavailableAfterAttempts = null;

    /**
     * Creates the test double.
     *
     * @param OutboxSender|null $inner sender that receives confirmed messages; null for none
     */
    public function __construct(private readonly ?OutboxSender $inner = null)
    {
    }

    /**
     * Makes the broker reject the messages of these entries.
     *
     * @param string ...$ids UUIDs of the entries in RFC 4122 format
     *
     * @return void
     */
    public function reject(string ...$ids): void
    {
        foreach ($ids as $id) {
            $this->rejectedIds[$id] = true;
        }
    }

    /**
     * Makes the broker reject every message.
     *
     * @return void
     */
    public function rejectEverything(): void
    {
        $this->rejectEverything = true;
    }

    /**
     * Lifts all rejections.
     *
     * @return void
     */
    public function stopRejecting(): void
    {
        $this->rejectedIds = [];
        $this->rejectEverything = false;
    }

    /**
     * Makes the broker unreachable after a number of further delivery attempts.
     *
     * @param int $afterAttempts number of delivery attempts that still reach the broker
     *
     * @return void
     */
    public function makeBrokerUnavailable(int $afterAttempts = 0): void
    {
        $this->unavailableAfterAttempts = count($this->attemptedIds) + $afterAttempts;
    }

    /**
     * Makes the broker reachable again.
     *
     * @return void
     */
    public function makeBrokerAvailable(): void
    {
        $this->unavailableAfterAttempts = null;
    }

    /**
     * Delivers the message according to the configured behaviour.
     *
     * @param OutboxEntry $entry entry to deliver
     *
     * @return void
     *
     * @throws MessageRejectedException   if the message is to be rejected
     * @throws BrokerUnavailableException if the broker is to be unreachable
     */
    public function send(OutboxEntry $entry): void
    {
        $id = $entry->getId()->toRfc4122();

        if ($this->unavailableAfterAttempts !== null && count($this->attemptedIds) >= $this->unavailableAfterAttempts) {
            throw new BrokerUnavailableException();
        }

        $this->attemptedIds[] = $id;

        if ($this->rejectEverything || isset($this->rejectedIds[$id])) {
            throw new MessageRejectedException();
        }

        $this->inner?->send($entry);
        $this->confirmed[] = $entry;
    }

    /**
     * Returns the UUIDs of the entries in the order of the delivery attempts.
     *
     * @return list<string> UUIDs in RFC 4122 format
     */
    public function attemptedIds(): array
    {
        return $this->attemptedIds;
    }

    /**
     * Returns the confirmed entries in the order of delivery.
     *
     * @return list<OutboxEntry> the confirmed entries
     */
    public function confirmed(): array
    {
        return $this->confirmed;
    }
}
