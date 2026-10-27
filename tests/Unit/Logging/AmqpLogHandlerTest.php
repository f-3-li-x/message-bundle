<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Logging;

use Error;
use AMQPExchange;
use Monolog\Level;
use DateTimeImmutable;
use Monolog\LogRecord;
use AMQPConnectionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use ACAT\MessageBundle\Logging\AmqpLogHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use ACAT\MessageBundle\Testing\AmqpMessageAssertions;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use ACAT\MessageBundle\Tests\Support\ScriptedAmqpExchangeFactory;

#[RequiresPhpExtension('amqp')]
final class AmqpLogHandlerTest extends TestCase
{
    use AmqpMessageAssertions;

    /** @var list<array{body: string, routingKey: string|null, attributes: array<array-key, mixed>}> */
    private array $published = [];

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->published = [];
        $this->clock = new MockClock('2026-07-21T10:00:00Z');
    }

    public function testConnectsWithTheFirstRecordAndKeepsTheConnection(): void
    {
        $factory = new ScriptedAmqpExchangeFactory($this->recordingExchange());
        $handler = $this->handler($factory);

        self::assertSame(0, $factory->connectionAttempts());

        $handler->handle(self::record(Level::Info, 'app'));
        $handler->handle(self::record(Level::Info, 'request'));

        self::assertSame(1, $factory->connectionAttempts());
        self::assertCount(2, $this->published);
    }

    /**
     * @return iterable<string, array{Level, string, string}>
     */
    public static function levelsChannelsAndRoutingKeys(): iterable
    {
        yield 'debug im Channel app' => [Level::Debug, 'app', 'debug.person-service'];
        yield 'error im Channel security' => [Level::Error, 'security', 'error.person-service'];
        yield 'critical im Channel php' => [Level::Critical, 'php', 'critical.person-service'];
    }

    #[DataProvider('levelsChannelsAndRoutingKeys')]
    public function testRoutesByLevelAndServiceWithTheAmqpProperties(Level $level, string $channel, string $key): void
    {
        $handler = $this->handler(new ScriptedAmqpExchangeFactory($this->recordingExchange()));

        $handler->handle(self::record($level, $channel));

        self::assertSame($key, $this->published[0]['routingKey']);
        self::assertAmqpProperties($this->published[0]['attributes'], $key, 'person-service');
    }

    public function testSwallowsAFailedConnectionAndPausesForSixtySeconds(): void
    {
        $factory = new ScriptedAmqpExchangeFactory(new AMQPConnectionException('nicht erreichbar'), $this->recordingExchange());
        $handler = $this->handler($factory);

        $handler->handle(self::record(Level::Error, 'app'));
        $this->clock->sleep(59);
        $handler->handle(self::record(Level::Error, 'app'));

        self::assertSame(1, $factory->connectionAttempts());
        self::assertSame([], $this->published);

        $this->clock->sleep(1);
        $handler->handle(self::record(Level::Error, 'app'));

        self::assertSame(2, $factory->connectionAttempts());
        self::assertCount(1, $this->published);
    }

    public function testSwallowsEveryFailureWhileSending(): void
    {
        $exchange = self::createStub(AMQPExchange::class);
        $exchange->method('publish')->willThrowException(new Error('unerwartet'));
        $factory = new ScriptedAmqpExchangeFactory($exchange);

        $this->handler($factory)->handle(self::record(Level::Error, 'app'));

        self::assertSame(1, $factory->connectionAttempts());
    }

    public function testReconnectsOnceWhenAnOpenConnectionFails(): void
    {
        $broken = self::createStub(AMQPExchange::class);
        $calls = 0;
        $broken->method('publish')->willReturnCallback(static function () use (&$calls): bool {
            if (++$calls > 1) {
                throw new AMQPConnectionException('Verbindung geschlossen');
            }

            return true;
        });
        $factory = new ScriptedAmqpExchangeFactory($broken, $this->recordingExchange());
        $handler = $this->handler($factory);

        $handler->handle(self::record(Level::Info, 'app'));
        $handler->handle(self::record(Level::Info, 'app'));

        self::assertSame(2, $factory->connectionAttempts());
        self::assertCount(1, $this->published);
    }

    public function testReplacesAConnectionThatWasIdleForAHeartbeatInterval(): void
    {
        $factory = new ScriptedAmqpExchangeFactory($this->recordingExchange());
        $handler = $this->handler($factory);

        $handler->handle(self::record(Level::Info, 'app'));
        $this->clock->sleep(60);
        $handler->handle(self::record(Level::Info, 'app'));

        self::assertSame(2, $factory->connectionAttempts());
    }

    public function testDisabledHandlerNeverConnects(): void
    {
        $factory = new ScriptedAmqpExchangeFactory($this->recordingExchange());
        $handler = new AmqpLogHandler($factory, 'person-service', 60, $this->clock, false);

        self::assertFalse($handler->handle(self::record(Level::Error, 'app')));
        self::assertSame(0, $factory->connectionAttempts());
    }

    private function handler(ScriptedAmqpExchangeFactory $factory): AmqpLogHandler
    {
        return new AmqpLogHandler($factory, 'person-service', 60, $this->clock);
    }

    private function recordingExchange(): AMQPExchange
    {
        $exchange = self::createStub(AMQPExchange::class);
        $exchange->method('publish')->willReturnCallback(
            function (string $body, ?string $key = null, ?int $flags = null, array $attributes = []): bool {
                $this->published[] = ['body' => $body, 'routingKey' => $key, 'attributes' => $attributes];

                return true;
            },
        );

        return $exchange;
    }

    private static function record(Level $level, string $channel): LogRecord
    {
        return new LogRecord(new DateTimeImmutable('2026-07-21T10:00:00.000Z'), $channel, $level, 'Eintrag');
    }
}
