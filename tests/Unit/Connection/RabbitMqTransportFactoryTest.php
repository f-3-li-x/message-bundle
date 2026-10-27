<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use F3lix\MessageBundle\Connection\RabbitMqConfig;
use F3lix\MessageBundle\Connection\RabbitMqTransportFactory;
use F3lix\MessageBundle\Tests\Support\RecordingTransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class RabbitMqTransportFactoryTest extends TestCase
{
    private RecordingTransportFactory $inner;

    protected function setUp(): void
    {
        $this->inner = new RecordingTransportFactory(self::createStub(TransportInterface::class));
    }

    public function testSupportsOnlyItsOwnDsn(): void
    {
        $factory = $this->factory(false);

        self::assertTrue($factory->supports('rabbitmq-env://', []));
        self::assertFalse($factory->supports('amqp://localhost', []));
    }

    public function testPassesTheConnectionDataFromTheRabbitMqVariables(): void
    {
        $this->factory(false)->createTransport('rabbitmq-env://', ['auto_setup' => false], self::serializer());

        self::assertSame('amqp://', $this->inner->dsn);
        self::assertSame(
            [
                'auto_setup'      => false,
                'host'            => 'rabbit',
                'port'            => 5672,
                'vhost'           => '/',
                'login'           => 'person-service',
                'password'        => 'secret',
                'heartbeat'       => 60,
                'connect_timeout' => 5.0,
            ],
            $this->inner->options,
        );
    }

    public function testEncryptedConnectionUsesAmqpsWithCaCertificates(): void
    {
        $this->factory(true)->createTransport('rabbitmq-env://', [], self::serializer());

        self::assertSame('amqps://', $this->inner->dsn);
        self::assertIsString($this->inner->options['cacert'] ?? null);
        self::assertNotSame('', $this->inner->options['cacert']);
    }

    public function testAnOptionOfTheTransportTakesPrecedence(): void
    {
        $this->factory(false)->createTransport('rabbitmq-env://', ['heartbeat' => 120], self::serializer());

        self::assertSame(120, $this->inner->options['heartbeat'] ?? null);
    }

    private function factory(bool $secure): RabbitMqTransportFactory
    {
        return new RabbitMqTransportFactory(
            new RabbitMqConfig('rabbit', 5672, 'person-service', 'secret', '/', $secure, 60),
            $this->inner,
        );
    }

    private static function serializer(): SerializerInterface
    {
        return self::createStub(SerializerInterface::class);
    }
}
