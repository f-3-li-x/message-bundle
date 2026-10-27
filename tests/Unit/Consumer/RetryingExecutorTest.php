<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Unit\Consumer;

use RuntimeException;
use DateTimeImmutable;
use Psr\Log\LogLevel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use F3lix\MessageBundle\Consumer\RetryingExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use F3lix\MessageBundle\Tests\Support\RecordingLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class RetryingExecutorTest extends TestCase
{
    private MockClock $clock;

    private RecordingLogger $logger;

    private int $resets = 0;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-21T10:00:00Z');
        $this->logger = new RecordingLogger();
        $this->resets = 0;
    }

    public function testRunsOnceWhenTheOperationSucceeds(): void
    {
        $calls = 0;

        $this->executor(10)->run(static function () use (&$calls): void {
            ++$calls;
        });

        self::assertSame(1, $calls);
        self::assertSame([], $this->logger->records);
        self::assertSame(0, $this->resets);
    }

    public function testRetriesWithTheFixedWaitingTimesAndResetsTheEntityManager(): void
    {
        $calls = 0;

        $this->executor(10)->run(static function () use (&$calls): void {
            if (++$calls < 4) {
                throw new RuntimeException('Datenbank nicht erreichbar');
            }
        }, ['ticketId' => 'ret-1']);

        self::assertSame(4, $calls);
        self::assertSame(3, $this->resets);
        self::assertSame(7, $this->clock->now()->getTimestamp() - (new DateTimeImmutable('2026-07-21T10:00:00Z'))->getTimestamp());
        self::assertSame([LogLevel::WARNING, LogLevel::WARNING, LogLevel::WARNING], $this->logger->levels());
        self::assertSame('ret-1', $this->logger->records[0]['context']['ticketId']);
    }

    public function testGivesUpAfterTheLastAttemptWithAnUnrecoverableException(): void
    {
        $calls = 0;

        try {
            $this->executor(10)->run(static function () use (&$calls): void {
                ++$calls;

                throw new RuntimeException('Datenbank nicht erreichbar');
            });
            self::fail('Die Verarbeitung hätte endgültig scheitern müssen.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        self::assertSame(10, $calls);
        self::assertSame(55, $this->clock->now()->getTimestamp() - (new DateTimeImmutable('2026-07-21T10:00:00Z'))->getTimestamp());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function configuredAndEffectiveAttempts(): iterable
    {
        yield 'unter 1' => [0, 1];
        yield '1' => [1, 1];
        yield '5' => [5, 5];
        yield '10' => [10, 10];
        yield 'über 10' => [25, 10];
    }

    #[DataProvider('configuredAndEffectiveAttempts')]
    public function testLimitsTheAttemptsToOneToTen(int $configured, int $effective): void
    {
        self::assertSame($effective, $this->executor($configured)->maxAttempts());
    }

    private function executor(int $maxAttempts): RetryingExecutor
    {
        $manager = self::createStub(ObjectManager::class);
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('resetManager')->willReturnCallback(function () use ($manager): ObjectManager {
            ++$this->resets;

            return $manager;
        });

        return new RetryingExecutor($maxAttempts, $registry, $this->clock, $this->logger);
    }
}
