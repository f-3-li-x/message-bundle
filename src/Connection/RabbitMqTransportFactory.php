<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Connection;

use SensitiveParameter;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Creates the AMQP transport for the DSN rabbitmq-env://: the connection data come from the RABBITMQ_* variables
 * instead of the DSN, the options of the transport (exchange, queues, auto_setup, ...) from its configuration.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final readonly class RabbitMqTransportFactory implements TransportFactoryInterface
{
    public const string DSN = 'rabbitmq-env://';

    /** Timeout for connecting the transports in seconds */
    private const float CONNECT_TIMEOUT = 5.0;

    /**
     * Creates the factory.
     *
     * @param RabbitMqConfig                                $config connection data from RABBITMQ_*
     * @param TransportFactoryInterface<TransportInterface> $amqp   factory of the AMQP transport of Symfony Messenger
     */
    public function __construct(
        private RabbitMqConfig $config,
        private TransportFactoryInterface $amqp,
    ) {
    }

    /**
     * Creates the AMQP transport with the connection data from RABBITMQ_*. Instead of the DSN, only the scheme is
     * passed on, amqps:// for an encrypted connection, otherwise amqp://; an option of the transport takes precedence
     * over the connection data of the same name.
     *
     * @param string               $dsn        rabbitmq-env://
     * @param array<string, mixed> $options    options of the transport
     * @param SerializerInterface  $serializer serializer of the transport
     *
     * @return TransportInterface the AMQP transport
     */
    public function createTransport(
        #[SensitiveParameter]
        string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        return $this->amqp->createTransport(
            $this->config->isSecure() ? 'amqps://' : 'amqp://',
            $options + $this->config->connectionOptions(self::CONNECT_TIMEOUT),
            $serializer,
        );
    }

    /**
     * Checks whether the DSN belongs to this factory.
     *
     * @param string               $dsn     DSN of the transport
     * @param array<string, mixed> $options options of the transport
     *
     * @return bool true for rabbitmq-env://
     */
    public function supports(
        #[SensitiveParameter]
        string $dsn,
        array $options,
    ): bool {
        return $dsn === self::DSN;
    }
}
