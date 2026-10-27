<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use JsonException;
use PHPUnit\Framework\Assert;
use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Deletion\DeletionRequest;

/**
 * Behaviour of a service on a deletion request, as tests to include in a test class of the service: deletion with
 * the feedback <entity>.deleted, unknown and invalid id with <entity>.deletion.failed and reason not_found, repeated
 * feedback with the time of the first processing when a request is delivered again. The service provides the hooks.
 */
trait DeletionRequestContract
{
    use EventFormatAssertions;

    /**
     * Returns the name of the entity that prefixes the feedback events.
     *
     * @return string e.g. person
     */
    abstract protected function deletionEntityName(): string;

    /**
     * Creates an entity that can be deleted.
     *
     * @return string its id
     */
    abstract protected function createEntityForDeletion(): string;

    /**
     * Processes a deletion request like the event consumer of the service.
     *
     * @param DeletionRequest $request deletion request
     *
     * @return void
     */
    abstract protected function handleDeletionRequest(DeletionRequest $request): void;

    /**
     * Returns the feedback events published or written to the outbox since the start of the test.
     *
     * @return list<array{type: string, body: string}> event name and body per feedback, in the order they were
     *                                                created
     */
    abstract protected function publishedDeletionFeedback(): array;

    /**
     * Checks that the entity is deleted or anonymised.
     *
     * @param string $id id of the entity
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
     * Checks the feedback <entity>.deletion.failed with reason not_found and the id as it was sent.
     *
     * @param string $ticketId ticketId of the request
     * @param string $id       id of the request
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
     * Returns the feedback events for a ticketId.
     *
     * @param string $ticketId ticketId of the request
     *
     * @return list<array{type: string, body: string}> the feedback events in the order they were created
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
     * Returns a unique ticketId for a test.
     *
     * @return string the ticketId
     */
    private static function contractTicketId(): string
    {
        return 'contract-' . Uuid::v7()->toRfc4122();
    }
}
