<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Unit\Outbox;

use Psr\Log\LogLevel;
use PHPUnit\Framework\TestCase;
use F3lix\MessageBundle\Outbox\OutboxPublisher;
use F3lix\MessageBundle\Tests\Support\OutboxEntries;
use F3lix\MessageBundle\Testing\ScriptedOutboxSender;
use F3lix\MessageBundle\Tests\Support\RecordingLogger;
use F3lix\MessageBundle\Testing\InMemoryOutboxEntryStore;

final class OutboxPublisherTest extends TestCase
{
    private const string FIRST = '019a0000-0000-7000-8000-000000000001';
    private const string SECOND = '019a0000-0000-7000-8000-000000000002';
    private const string THIRD = '019a0000-0000-7000-8000-000000000003';
    private const string OTHER_AGGREGATE = '018f2c3a-9a1b-7d2e-8f3a-000000000099';

    private InMemoryOutboxEntryStore $store;

    private ScriptedOutboxSender $sender;

    private RecordingLogger $logger;

    private OutboxPublisher $publisher;

    protected function setUp(): void
    {
        $this->store = new InMemoryOutboxEntryStore();
        $this->sender = new ScriptedOutboxSender();
        $this->logger = new RecordingLogger();
        $this->publisher = new OutboxPublisher($this->store, $this->sender, $this->logger);
    }

    public function testDeliversInTheOrderOfCreationAndRemovesDeliveredEntries(): void
    {
        $this->store->add(OutboxEntries::pending(self::SECOND, createdAt: '2026-07-21T10:00:01.000Z'));
        $this->store->add(OutboxEntries::pending(self::FIRST, createdAt: '2026-07-21T10:00:00.000Z'));

        $result = $this->publisher->publish();

        self::assertSame([self::FIRST, self::SECOND], $this->sender->attemptedIds());
        self::assertSame(2, $result->delivered);
        self::assertFalse($result->aborted);
        self::assertSame([], $this->store->findAllOrdered());
    }

    public function testOrdersByIdWhenCreatedAtIsEqual(): void
    {
        $this->store->add(OutboxEntries::pending(self::SECOND));
        $this->store->add(OutboxEntries::pending(self::FIRST));

        $this->publisher->publish();

        self::assertSame([self::FIRST, self::SECOND], $this->sender->attemptedIds());
    }

    public function testRejectedEntryCountsAnAttemptAndBlocksLaterEntriesOfTheSameAggregate(): void
    {
        $this->store->add(OutboxEntries::pending(self::FIRST, createdAt: '2026-07-21T10:00:00.000Z'));
        $this->store->add(OutboxEntries::pending(self::SECOND, createdAt: '2026-07-21T10:00:01.000Z'));
        $this->store->add(OutboxEntries::pending(
            self::THIRD,
            aggregateId: self::OTHER_AGGREGATE,
            createdAt: '2026-07-21T10:00:02.000Z',
        ));
        $this->sender->reject(self::FIRST);

        $result = $this->publisher->publish();

        self::assertSame([self::FIRST, self::THIRD], $this->sender->attemptedIds());
        self::assertSame(1, $result->rejected);
        self::assertSame(1, $result->skipped);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $this->store->find(self::FIRST)?->getAttempts());
        self::assertNotNull($this->store->find(self::SECOND));
        self::assertSame([LogLevel::WARNING], $this->logger->levels());
    }

    public function testFailedEntryBlocksLaterEntriesOfTheSameAggregate(): void
    {
        $this->store->add(OutboxEntries::failed(self::FIRST, createdAt: '2026-07-21T10:00:00.000Z'));
        $this->store->add(OutboxEntries::pending(self::SECOND, createdAt: '2026-07-21T10:00:01.000Z'));

        $result = $this->publisher->publish();

        self::assertSame([], $this->sender->attemptedIds());
        self::assertSame(1, $result->skipped);
    }

    public function testTenthRejectionMakesTheEntryFailedAndLogsAnError(): void
    {
        $this->store->add(OutboxEntries::pending(self::FIRST));
        $this->sender->rejectEverything();

        for ($run = 0; $run < 10; ++$run) {
            $this->publisher->publish();
        }

        self::assertTrue($this->store->find(self::FIRST)?->isFailed());
        self::assertSame(LogLevel::ERROR, $this->logger->levels()[9]);

        $this->publisher->publish();

        self::assertCount(10, $this->sender->attemptedIds());
    }

    public function testUnavailableBrokerAbortsTheRunWithoutCountingAttempts(): void
    {
        $this->store->add(OutboxEntries::pending(self::FIRST, createdAt: '2026-07-21T10:00:00.000Z'));
        $this->store->add(OutboxEntries::pending(
            self::SECOND,
            aggregateId: self::OTHER_AGGREGATE,
            createdAt: '2026-07-21T10:00:01.000Z',
        ));
        $this->sender->makeBrokerUnavailable();

        $result = $this->publisher->publish();

        self::assertTrue($result->aborted);
        self::assertSame(0, $result->delivered);
        self::assertSame(0, $this->store->find(self::FIRST)?->getAttempts());
        self::assertCount(2, $this->store->findAllOrdered());
        self::assertSame([LogLevel::ERROR], $this->logger->levels());
    }

    public function testLogContextHoldsNoPayload(): void
    {
        $this->store->add(OutboxEntries::pending(self::FIRST));
        $this->sender->reject(self::FIRST);

        $this->publisher->publish();

        self::assertSame(
            ['id', 'eventType', 'aggregateId', 'attempts', 'exception'],
            array_keys($this->logger->records[0]['context']),
        );
    }
}
