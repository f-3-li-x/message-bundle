<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Support;

use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Factory, die DSN und Optionen des zuletzt erzeugten Transports aufzeichnet.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final class RecordingTransportFactory implements TransportFactoryInterface
{
    public ?string $dsn = null;

    /** @var array<string, mixed>|null */
    public ?array $options = null;

    /**
     * Erzeugt die Factory.
     *
     * @param TransportInterface $transport Transport, den die Factory liefert
     */
    public function __construct(private readonly TransportInterface $transport)
    {
    }

    /**
     * Zeichnet DSN und Optionen auf.
     *
     * @param string               $dsn        DSN
     * @param array<string, mixed> $options    Optionen
     * @param SerializerInterface  $serializer Serializer
     *
     * @return TransportInterface der hinterlegte Transport
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $this->dsn = $dsn;
        $this->options = $options;

        return $this->transport;
    }

    /**
     * Unterstützt jeden DSN.
     *
     * @param string               $dsn     DSN
     * @param array<string, mixed> $options Optionen
     *
     * @return bool immer true
     */
    public function supports(string $dsn, array $options): bool
    {
        return true;
    }
}
