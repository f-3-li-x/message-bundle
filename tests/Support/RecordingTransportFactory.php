<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Support;

use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Factory that records DSN and options of the most recently created transport.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final class RecordingTransportFactory implements TransportFactoryInterface
{
    public ?string $dsn = null;

    /** @var array<string, mixed>|null */
    public ?array $options = null;

    /**
     * Creates the factory.
     *
     * @param TransportInterface $transport transport the factory returns
     */
    public function __construct(private readonly TransportInterface $transport)
    {
    }

    /**
     * Records DSN and options.
     *
     * @param string               $dsn        DSN
     * @param array<string, mixed> $options    options
     * @param SerializerInterface  $serializer serializer
     *
     * @return TransportInterface the given transport
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $this->dsn = $dsn;
        $this->options = $options;

        return $this->transport;
    }

    /**
     * Supports every DSN.
     *
     * @param string               $dsn     DSN
     * @param array<string, mixed> $options options
     *
     * @return bool always true
     */
    public function supports(string $dsn, array $options): bool
    {
        return true;
    }
}
