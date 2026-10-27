<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Testing;

use JsonException;
use PHPUnit\Framework\Assert;
use Symfony\Component\Uid\Uuid;
use F3lix\MessageBundle\Deletion\DeletionRequest;

/**
 * Verhalten eines Service beim Löschauftrag, als Tests zum Einbinden in eine Testklasse des Service:
 * Löschung mit Rückmeldung <entität>.deleted, unbekannte und ungültige id mit <entität>.deletion.failed und
 * reason not_found, erneute Rückmeldung bei wiederholt zugestelltem Auftrag mit dem Zeitpunkt der
 * Erstverarbeitung. Der Service liefert die Hooks.
 */
trait DeletionRequestContract
{
    use EventFormatAssertions;

    /**
     * Liefert den Namen der Entität, der den Rückmeldungen vorangestellt ist.
     *
     * @return string z. B. person
     */
    abstract protected function deletionEntityName(): string;

    /**
     * Legt eine Entität an, die gelöscht werden kann.
     *
     * @return string ihre id
     */
    abstract protected function createEntityForDeletion(): string;

    /**
     * Verarbeitet einen Löschauftrag wie der Event-Consumer des Service.
     *
     * @param DeletionRequest $request Löschauftrag
     *
     * @return void
     */
    abstract protected function handleDeletionRequest(DeletionRequest $request): void;

    /**
     * Liefert die veröffentlichten oder in der Outbox abgelegten Rückmeldungen seit Beginn des Tests.
     *
     * @return list<array{type: string, body: string}> Event-Name und Body je Rückmeldung, in der Reihenfolge ihres
     *                                                Entstehens
     */
    abstract protected function publishedDeletionFeedback(): array;

    /**
     * Prüft, dass die Entität gelöscht bzw. anonymisiert ist.
     *
     * @param string $id id der Entität
     *
     * @return void
     */
    abstract protected function assertEntityDeleted(string $id): void;

    public function testDeletionRequestDeletesTheEntityAndReportsDeleted(): void
    {
        $id = $this->createEntityForDeletion();
        $ticketId = self::contractTicketId();

        $this->handleDeletionRequest(new DeletionRequest($id, $ticketId));

        $feedback = $this->deletionFeedbackFor($ticketId);
        Assert::assertCount(1, $feedback, 'Es entsteht genau eine Rückmeldung.');
        Assert::assertSame($this->deletionEntityName() . '.deleted', $feedback[0]['type']);
        Assert::assertSame($id, self::assertDeletedEvent($feedback[0]['body'])['id']);
        $this->assertEntityDeleted($id);
    }

    public function testDeletionRequestForUnknownIdReportsNotFound(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $ticketId = self::contractTicketId();

        $this->handleDeletionRequest(new DeletionRequest($id, $ticketId));

        $this->assertNotFoundReported($ticketId, $id);
    }

    public function testDeletionRequestWithInvalidIdReportsNotFound(): void
    {
        $ticketId = self::contractTicketId();

        $this->handleDeletionRequest(new DeletionRequest('keine-uuid-v7', $ticketId));

        $this->assertNotFoundReported($ticketId, 'keine-uuid-v7');
    }

    public function testRepeatedDeletionRequestRepublishesTheOriginalFeedback(): void
    {
        $id = $this->createEntityForDeletion();
        $ticketId = self::contractTicketId();

        $this->handleDeletionRequest(new DeletionRequest($id, $ticketId));
        $this->handleDeletionRequest(new DeletionRequest($id, $ticketId));

        $feedback = $this->deletionFeedbackFor($ticketId);
        Assert::assertCount(2, $feedback, 'Der wiederholte Auftrag wird erneut zurückgemeldet.');
        Assert::assertSame($this->deletionEntityName() . '.deleted', $feedback[1]['type']);
        Assert::assertSame($feedback[0]['body'], $feedback[1]['body'], 'Die erneute Rückmeldung gleicht der ersten.');
    }

    public function testRepeatedDeletionRequestForUnknownIdRepublishesNotFound(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $ticketId = self::contractTicketId();

        $this->handleDeletionRequest(new DeletionRequest($id, $ticketId));
        $this->handleDeletionRequest(new DeletionRequest($id, $ticketId));

        $feedback = $this->deletionFeedbackFor($ticketId);
        Assert::assertCount(2, $feedback, 'Der wiederholte Auftrag wird erneut zurückgemeldet.');
        Assert::assertSame($this->deletionEntityName() . '.deletion.failed', $feedback[1]['type']);
        Assert::assertSame($feedback[0]['body'], $feedback[1]['body'], 'Die erneute Rückmeldung gleicht der ersten.');
    }

    /**
     * Prüft die Rückmeldung <entität>.deletion.failed mit reason not_found und der id wie übermittelt.
     *
     * @param string $ticketId ticketId des Auftrags
     * @param string $id       id des Auftrags
     *
     * @return void
     */
    private function assertNotFoundReported(string $ticketId, string $id): void
    {
        $feedback = $this->deletionFeedbackFor($ticketId);
        Assert::assertCount(1, $feedback, 'Es entsteht genau eine Rückmeldung.');
        Assert::assertSame($this->deletionEntityName() . '.deletion.failed', $feedback[0]['type']);

        $payload = self::assertDeletionFailedEvent($feedback[0]['body']);
        Assert::assertSame($id, $payload['id']);
        Assert::assertSame('not_found', $payload['reason']);
    }

    /**
     * Liefert die Rückmeldungen zu einer ticketId.
     *
     * @param string $ticketId ticketId des Auftrags
     *
     * @return list<array{type: string, body: string}> die Rückmeldungen in der Reihenfolge ihres Entstehens
     */
    private function deletionFeedbackFor(string $ticketId): array
    {
        return array_values(array_filter(
            $this->publishedDeletionFeedback(),
            static function (array $feedback) use ($ticketId): bool {
                try {
                    $payload = json_decode($feedback['body'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return false;
                }

                return is_array($payload) && ($payload['ticketId'] ?? null) === $ticketId;
            },
        ));
    }

    /**
     * Liefert eine eindeutige ticketId für einen Test.
     *
     * @return string die ticketId
     */
    private static function contractTicketId(): string
    {
        return 'contract-' . Uuid::v7()->toRfc4122();
    }
}
