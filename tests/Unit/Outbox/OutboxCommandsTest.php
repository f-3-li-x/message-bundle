<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Outbox;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use ACAT\MessageBundle\Outbox\OutboxPublisher;
use ACAT\MessageBundle\Tests\Support\OutboxEntries;
use ACAT\MessageBundle\Testing\ScriptedOutboxSender;
use Symfony\Component\Console\Tester\CommandTester;
use ACAT\MessageBundle\Testing\InMemoryOutboxEntryStore;
use ACAT\MessageBundle\Outbox\Command\PublishOutboxCommand;
use ACAT\MessageBundle\Outbox\Command\RetryFailedOutboxCommand;

final class OutboxCommandsTest extends TestCase
{
    private const string FIRST = '019a0000-0000-7000-8000-000000000001';
    private const string SECOND = '019a0000-0000-7000-8000-000000000002';

    public function testPublishReportsTheRun(): void
    {
        $store = new InMemoryOutboxEntryStore(OutboxEntries::pending(self::FIRST));
        $tester = $this->publishCommand($store, new ScriptedOutboxSender());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 delivered, 0 rejected, 0 skipped', $tester->getDisplay());
    }

    public function testPublishFailsWhenTheBrokerIsUnavailable(): void
    {
        $sender = new ScriptedOutboxSender();
        $sender->makeBrokerUnavailable();
        $tester = $this->publishCommand(new InMemoryOutboxEntryStore(OutboxEntries::pending(self::FIRST)), $sender);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('RabbitMQ is unreachable', $tester->getDisplay());
    }

    public function testRetryFailedResetsAllFailedEntries(): void
    {
        $store = new InMemoryOutboxEntryStore(OutboxEntries::failed(self::FIRST), OutboxEntries::failed(self::SECOND));
        $tester = new CommandTester(new RetryFailedOutboxCommand($store));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Outbox entries set to pending: 2', $tester->getDisplay());
        self::assertFalse($store->find(self::FIRST)?->isFailed());
        self::assertSame(0, $store->find(self::SECOND)?->getAttempts());
    }

    public function testRetryFailedResetsASingleEntry(): void
    {
        $store = new InMemoryOutboxEntryStore(OutboxEntries::failed(self::FIRST), OutboxEntries::failed(self::SECOND));
        $tester = new CommandTester(new RetryFailedOutboxCommand($store));

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => self::SECOND]));
        self::assertTrue($store->find(self::FIRST)?->isFailed());
        self::assertFalse($store->find(self::SECOND)?->isFailed());
    }

    public function testRetryFailedRejectsAnIdThatIsNoUuid(): void
    {
        $tester = new CommandTester(new RetryFailedOutboxCommand(new InMemoryOutboxEntryStore()));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'not-a-uuid']));
        self::assertStringContainsString('not a UUID', $tester->getDisplay());
    }

    public function testRetryFailedFailsForAnIdWithoutFailedEntry(): void
    {
        $tester = new CommandTester(new RetryFailedOutboxCommand(
            new InMemoryOutboxEntryStore(OutboxEntries::pending(self::FIRST)),
        ));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => self::FIRST]));
    }

    private function publishCommand(InMemoryOutboxEntryStore $store, ScriptedOutboxSender $sender): CommandTester
    {
        return new CommandTester(new PublishOutboxCommand(
            new OutboxPublisher($store, $sender, new NullLogger()),
            new MockClock('2026-07-21T10:00:00Z'),
        ));
    }
}
