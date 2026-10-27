<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\AssertionFailedError;
use F3lix\MessageBundle\Testing\EventFormatAssertions;

final class EventFormatAssertionsTest extends TestCase
{
    use EventFormatAssertions;

    private const string UPDATED = <<<'JSON'
        {"id":"018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a","actorId":"f47c1c2e","timestamp":"2026-07-21T10:20:00.000Z",
        "delta":[
          {"field":"name","operation":"changed","oldValue":"Alt","newValue":"Neu"},
          {"field":"firstName","operation":"changed","masked":true},
          {"field":"contacts[b2e1]","operation":"removed","oldValue":{"contactType":"phone"},"newValue":null,"masked":true},
          {"field":"address","operation":"added","oldValue":null,"newValue":{}}
        ]}
        JSON;

    public function testAcceptsValidEvents(): void
    {
        self::assertCreatedEvent('{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00.000Z"}');
        self::assertUpdatedEvent(self::UPDATED);
        self::assertDeletedEvent('{"id":"x","timestamp":"2026-07-21T10:00:00.000Z","ticketId":"ret-1"}');
        self::assertDeletionFailedEvent(
            '{"id":"x","timestamp":"2026-07-21T10:00:00.000Z","ticketId":"ret-1","reason":"not_found"}',
        );
        self::assertDeletionProcessedEvent(
            '{"id":"x","timestamp":"2026-07-21T10:00:00.000Z","ticketId":"ret-1","affectedPrograms":0}',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEvents(): iterable
    {
        yield 'actorId nicht direkt nach id' => [
            'created',
            '{"actorId":"a","id":"x","timestamp":"2026-07-21T10:00:00.000Z"}',
        ];
        yield 'Timestamp mit Offset' => ['created', '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00+00:00"}'];
        yield 'Timestamp ohne Millisekunden' => ['created', '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00Z"}'];
        yield 'leeres delta' => ['updated', '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00.000Z","delta":[]}'];
        yield 'maskierter Eintrag mit Werten' => [
            'updated',
            '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00.000Z","delta":[{"field":"f","operation":"changed",'
            . '"oldValue":"a","newValue":"b","masked":true}]}',
        ];
        yield 'added mit oldValue' => [
            'updated',
            '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00.000Z","delta":[{"field":"f","operation":"added",'
            . '"oldValue":"a","newValue":"b"}]}',
        ];
        yield 'unbekannte operation' => [
            'updated',
            '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00.000Z","delta":[{"field":"f","operation":"set",'
            . '"oldValue":"a","newValue":"b"}]}',
        ];
        yield 'deleted mit actorId' => [
            'deleted',
            '{"id":"x","actorId":"a","timestamp":"2026-07-21T10:00:00.000Z","ticketId":"ret-1"}',
        ];
        yield 'deletion.failed ohne timestamp' => ['failed', '{"id":"x","ticketId":"ret-1","reason":"not_found"}'];
        yield 'kein JSON' => ['created', '{'];
    }

    #[DataProvider('invalidEvents')]
    public function testRejectsEventsThatDeviateFromTheFormat(string $kind, string $body): void
    {
        $this->expectException(AssertionFailedError::class);

        match ($kind) {
            'created' => self::assertCreatedEvent($body),
            'updated' => self::assertUpdatedEvent($body),
            'deleted' => self::assertDeletedEvent($body),
            default   => self::assertDeletionFailedEvent($body),
        };
    }
}
