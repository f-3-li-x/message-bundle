<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use ACAT\MessageBundle\Outbox\OutboxSender;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use ACAT\MessageBundle\Outbox\MessageRejectedException;
use ACAT\MessageBundle\Outbox\BrokerUnavailableException;

/**
 * Test-Double für die Übergabe an RabbitMQ: stellt Ablehnung einzelner Nachrichten und Nichterreichbarkeit des
 * Brokers nach und reicht bestätigte Nachrichten wahlweise an einen echten Sender weiter, z. B. an
 * MessengerOutboxSender mit In-Memory-Transports.
 */
final class ScriptedOutboxSender implements OutboxSender
{
    /** @var array<string, true> UUIDs der Einträge, deren Nachricht abgelehnt wird */
    private array $rejectedIds = [];

    /** @var list<string> UUIDs der Einträge in der Reihenfolge der Zustellversuche */
    private array $attemptedIds = [];

    /** @var list<OutboxEntry> bestätigte Einträge in der Reihenfolge der Zustellung */
    private array $confirmed = [];

    private bool $rejectEverything = false;

    private ?int $unavailableAfterAttempts = null;

    /**
     * Erzeugt das Test-Double.
     *
     * @param OutboxSender|null $inner Sender, an den bestätigte Nachrichten weitergehen; null ohne Weitergabe
     */
    public function __construct(private readonly ?OutboxSender $inner = null)
    {
    }

    /**
     * Lässt den Broker die Nachrichten dieser Einträge ablehnen.
     *
     * @param string ...$ids UUIDs der Einträge im Format RFC 4122
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
     * Lässt den Broker jede Nachricht ablehnen.
     *
     * @return void
     */
    public function rejectEverything(): void
    {
        $this->rejectEverything = true;
    }

    /**
     * Hebt alle Ablehnungen auf.
     *
     * @return void
     */
    public function stopRejecting(): void
    {
        $this->rejectedIds = [];
        $this->rejectEverything = false;
    }

    /**
     * Macht den Broker nach einer Zahl weiterer Zustellversuche unerreichbar.
     *
     * @param int $afterAttempts Zahl der Zustellversuche, die noch den Broker erreichen
     *
     * @return void
     */
    public function makeBrokerUnavailable(int $afterAttempts = 0): void
    {
        $this->unavailableAfterAttempts = count($this->attemptedIds) + $afterAttempts;
    }

    /**
     * Macht den Broker wieder erreichbar.
     *
     * @return void
     */
    public function makeBrokerAvailable(): void
    {
        $this->unavailableAfterAttempts = null;
    }

    /**
     * Stellt die Nachricht nach dem eingestellten Verhalten zu.
     *
     * @param OutboxEntry $entry zuzustellender Eintrag
     *
     * @return void
     *
     * @throws MessageRejectedException   wenn die Nachricht abgelehnt werden soll
     * @throws BrokerUnavailableException wenn der Broker unerreichbar sein soll
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
     * Liefert die UUIDs der Einträge in der Reihenfolge der Zustellversuche.
     *
     * @return list<string> UUIDs im Format RFC 4122
     */
    public function attemptedIds(): array
    {
        return $this->attemptedIds;
    }

    /**
     * Liefert die bestätigten Einträge in der Reihenfolge der Zustellung.
     *
     * @return list<OutboxEntry> die bestätigten Einträge
     */
    public function confirmed(): array
    {
        return $this->confirmed;
    }
}
