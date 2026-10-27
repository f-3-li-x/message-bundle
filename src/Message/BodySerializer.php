<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Message;

use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer of the transports the outbox publisher publishes through: the body of the message is only the payload of
 * the event. No headers are created; the AmqpStamp sets the AMQP properties.
 */
final readonly class BodySerializer implements SerializerInterface
{
    /**
     * Turns an encoded message into an envelope with a RawMessage.
     *
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope encoded message
     *
     * @return Envelope the envelope with the body of the message
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        return new Envelope(new RawMessage($encodedEnvelope['body']));
    }

    /**
     * Encodes the envelope of a RawMessage: the body is taken over unchanged.
     *
     * @param Envelope $envelope envelope with a RawMessage
     *
     * @return array{body: string} the encoded message
     *
     * @throws LogicException if the envelope does not contain a RawMessage
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
