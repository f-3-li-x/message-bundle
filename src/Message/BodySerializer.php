<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Message;

use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer der Transports, über die der Outbox-Publisher veröffentlicht: Der Body der Nachricht ist
 * ausschließlich das Payload des Events. Es entstehen keine Header; die AMQP-Properties setzt der AmqpStamp.
 */
final readonly class BodySerializer implements SerializerInterface
{
    /**
     * Bildet aus einer kodierten Nachricht den Umschlag mit der RawMessage.
     *
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope kodierte Nachricht
     *
     * @return Envelope der Umschlag mit dem Body der Nachricht
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        return new Envelope(new RawMessage($encodedEnvelope['body']));
    }

    /**
     * Kodiert den Umschlag einer RawMessage: Der Body wird unverändert übernommen.
     *
     * @param Envelope $envelope Umschlag mit einer RawMessage
     *
     * @return array{body: string} die kodierte Nachricht
     *
     * @throws LogicException wenn der Umschlag keine RawMessage enthält
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if (!$message instanceof RawMessage) {
            throw new LogicException(
                sprintf('Über diesen Transport gehen nur Nachrichten der Klasse %s.', RawMessage::class),
            );
        }

        return ['body' => $message->body];
    }
}
