<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use Throwable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Test double for a Messenger transport: records the sent envelopes or throws a given failure, e.g. a
 * TransportException caused by an AMQPConnectionException.
 */
final class RecordingMessengerSender implements SenderInterface
{
    /** @var list<Envelope> sent envelopes */
    private array $envelopes = [];

    private ?Throwable $failure = null;

    /**
     * Makes every further send fail with this failure.
     *
     * @param Throwable|null $failure failure, or null for successful sending
     *
     * @return void
     */
    public function failWith(?Throwable $failure): void
    {
        $this->failure = $failure;
    }

    /**
     * Records the envelope.
     *
     * @param Envelope $envelope sent envelope
     *
     * @return Envelope the envelope
     *
     * @throws Throwable the given failure
     */
    public function send(Envelope $envelope): Envelope
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->envelopes[] = $envelope;

        return $envelope;
    }

    /**
     * Returns the sent envelopes.
     *
     * @return list<Envelope> the envelopes in the order they were sent
     */
    public function envelopes(): array
    {
        return $this->envelopes;
    }
}
