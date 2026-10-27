<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Outbox;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use ACAT\MessageBundle\Outbox\Entity\OutboxStatus;

final class OutboxEntryTest extends TestCase
{
    public function testStartsPendingWithoutAttempts(): void
    {
        $entry = self::entry();

        self::assertSame(OutboxStatus::Pending, $entry->getStatus());
        self::assertSame(0, $entry->getAttempts());
        self::assertFalse($entry->isFailed());
    }

    public function testKeepsThePayloadAsText(): void
    {
        $entry = self::entry('{"id":"x","delta":[{"field":"address","operation":"added","oldValue":null,"newValue":{}}]}');

        self::assertSame(
            '{"id":"x","delta":[{"field":"address","operation":"added","oldValue":null,"newValue":{}}]}',
            $entry->getPayload(),
        );
    }

    public function testNormalizesCreatedAtToUtcWithMilliseconds(): void
    {
        $entry = self::entry('{}', new DateTimeImmutable('2026-07-21T12:00:00.123456+02:00'));

        self::assertSame('2026-07-21T10:00:00.123000+00:00', $entry->getCreatedAt()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testBecomesFailedWithTheTenthFailedAttempt(): void
    {
        $entry = self::entry();

        for ($attempt = 1; $attempt < OutboxEntry::MAX_ATTEMPTS; ++$attempt) {
            $entry->recordFailedAttempt();
        }

        self::assertFalse($entry->isFailed());

        $entry->recordFailedAttempt();

        self::assertTrue($entry->isFailed());
        self::assertSame(10, $entry->getAttempts());
    }

    public function testRetryMakesAFailedEntryPendingAgain(): void
    {
        $entry = self::entry();

        for ($attempt = 0; $attempt < OutboxEntry::MAX_ATTEMPTS; ++$attempt) {
            $entry->recordFailedAttempt();
        }

        $entry->retry();

        self::assertSame(OutboxStatus::Pending, $entry->getStatus());
        self::assertSame(0, $entry->getAttempts());
    }

    private static function entry(string $payload = '{}', ?DateTimeImmutable $createdAt = null): OutboxEntry
    {
        return new OutboxEntry(
            Uuid::fromString('019a0000-0000-7000-8000-000000000001'),
            '018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a',
            'person.updated',
            'domain-events',
            $payload,
            $createdAt ?? new DateTimeImmutable('2026-07-21T10:00:00.000Z'),
        );
    }
}
