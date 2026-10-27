<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Testing;

use Throwable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Test-Double für einen Messenger-Transport: zeichnet die gesendeten Umschläge auf oder wirft einen vorgegebenen
 * Fehler, z. B. eine TransportException mit einer AMQPConnectionException als Ursache.
 */
final class RecordingMessengerSender implements SenderInterface
{
    /** @var list<Envelope> gesendete Umschläge */
    private array $envelopes = [];

    private ?Throwable $failure = null;

    /**
     * Lässt jeden weiteren Versand mit diesem Fehler scheitern.
     *
     * @param Throwable|null $failure Fehler oder null für erfolgreichen Versand
     *
     * @return void
     */
    public function failWith(?Throwable $failure): void
    {
        $this->failure = $failure;
    }

    /**
     * Zeichnet den Umschlag auf.
     *
     * @param Envelope $envelope gesendeter Umschlag
     *
     * @return Envelope der Umschlag
     *
     * @throws Throwable der vorgegebene Fehler
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
     * Liefert die gesendeten Umschläge.
     *
     * @return list<Envelope> die Umschläge in der Reihenfolge des Versands
     */
    public function envelopes(): array
    {
        return $this->envelopes;
    }
}
