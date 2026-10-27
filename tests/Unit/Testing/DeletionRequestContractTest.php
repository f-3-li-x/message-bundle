<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Testing;

use JsonException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Deletion\DeletionRequest;
use ACAT\MessageBundle\Testing\DeletionRequestContract;

/**
 * Bindet den Vertrag des Löschauftrags an einen minimalen Service im Speicher, der den Vertrag erfüllt; zeigt, wie ein
 * Service den Vertrag einbindet.
 */
final class DeletionRequestContractTest extends TestCase
{
    use DeletionRequestContract;

    /** @var array<string, true> vorhandene Entitäten */
    private array $entities = [];

    /** @var array<string, array{type: string, body: string}> gespeicherte Rückmeldung je ticketId */
    private array $processed = [];

    /** @var list<array{type: string, body: string}> */
    private array $feedback = [];

    protected function deletionEntityName(): string
    {
        return 'person';
    }

    protected function createEntityForDeletion(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->entities[$id] = true;

        return $id;
    }

    /**
     * @throws JsonException
     */
    protected function handleDeletionRequest(DeletionRequest $request): void
    {
        if (!isset($this->processed[$request->ticketId])) {
            $timestamp = (new DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
            $exists = isset($this->entities[$request->id]);
            unset($this->entities[$request->id]);

            $this->processed[$request->ticketId] = $exists
                ? ['type' => 'person.deleted', 'body' => json_encode(
                    ['id' => $request->id, 'timestamp' => $timestamp, 'ticketId' => $request->ticketId],
                    JSON_THROW_ON_ERROR,
                )]
                : ['type' => 'person.deletion.failed', 'body' => json_encode(
                    ['id' => $request->id, 'timestamp' => $timestamp, 'ticketId' => $request->ticketId, 'reason' => 'not_found'],
                    JSON_THROW_ON_ERROR,
                )];
        }

        $this->feedback[] = $this->processed[$request->ticketId];
    }

    protected function publishedDeletionFeedback(): array
    {
        return $this->feedback;
    }

    protected function assertEntityDeleted(string $id): void
    {
        self::assertArrayNotHasKey($id, $this->entities);
    }
}
