<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Deletion;

use stdClass;
use JsonException;
use LogicException;
use UnexpectedValueException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Transport serializer for deletion requests (deletion.requested): decodes the raw JSON body of the message (payload
 * id and ticketId). A structurally invalid request is reported as not decodable; Symfony Messenger then rejects it
 * without retries (reject without requeue) and logs the rejection with the reason given here. The reason names the
 * affected field, never a value.
 */
final readonly class DeletionRequestSerializer implements SerializerInterface
{
    private const int MAX_LENGTH = 255;
    private const string REJECTION_PREFIX = 'Strukturell ungültiger Löschauftrag: ';
    private const string JSON_WHITESPACE = " \t\n\r";

    /**
     * Decodes the deletion request. It is structurally invalid if the payload is empty, not valid JSON or not a JSON
     * object, or if id or ticketId is missing, empty, not a string or longer than 255 characters. Unknown additional
     * fields are ignored.
     *
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope raw message of the transport
     *
     * @return Envelope the deletion request or, for a structurally invalid request, the undecodable message as
     *                  MessageDecodingFailedException
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $payload = self::decodeJsonObject($encodedEnvelope['body']);

            return new Envelope(new DeletionRequest(
                self::readField($payload, 'id'),
                self::readField($payload, 'ticketId'),
            ));
        } catch (UnexpectedValueException $defect) {
            return MessageDecodingFailedException::wrap(
                $encodedEnvelope,
                self::REJECTION_PREFIX . $defect->getMessage(),
            );
        }
    }

    /**
     * Encodes a deletion request as a JSON body with id and ticketId. An undecodable message keeps its original raw
     * form.
     *
     * @param Envelope $envelope deletion request or undecodable message
     *
     * @return array{body: string, headers?: array<string, string>} raw message for the transport
     *
     * @throws LogicException if the message is not a deletion request
     * @throws JsonException  if the deletion request cannot be encoded as JSON
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if ($message instanceof MessageDecodingFailedException) {
            return self::originalEncodedEnvelope($message);
        }

        if (!$message instanceof DeletionRequest) {
            throw new LogicException(sprintf('Der Serializer kodiert ausschließlich %s.', DeletionRequest::class));
        }

        return [
            'body'    => json_encode(['id' => $message->id, 'ticketId' => $message->ticketId], JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json'],
        ];
    }

    /**
     * Decodes the body as a JSON object. A body consisting only of JSON whitespace also counts as empty.
     *
     * @param string $body raw body of the message
     *
     * @return array<array-key, mixed> fields of the JSON object
     *
     * @throws UnexpectedValueException if the body is empty, not valid JSON or not a JSON object
     */
    private static function decodeJsonObject(string $body): array
    {
        if (trim($body, self::JSON_WHITESPACE) === '') {
            throw new UnexpectedValueException('Das Payload ist leer.');
        }

        try {
            $payload = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Das Payload ist kein gültiges JSON.');
        }

        if (!$payload instanceof stdClass) {
            throw new UnexpectedValueException('Das Payload ist kein JSON-Objekt.');
        }

        return get_object_vars($payload);
    }

    /**
     * Reads a required field of the payload.
     *
     * @param array<array-key, mixed> $payload fields of the JSON object
     * @param string                  $field   name of the field
     *
     * @return string the value of the field
     *
     * @throws UnexpectedValueException if the field is missing, not a string, empty or longer than 255 characters
     */
    private static function readField(array $payload, string $field): string
    {
        if (!array_key_exists($field, $payload)) {
            throw new UnexpectedValueException(sprintf('Das Feld "%s" fehlt.', $field));
        }

        $value = $payload[$field];

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Das Feld "%s" ist kein String.', $field));
        }

        if ($value === '') {
            throw new UnexpectedValueException(sprintf('Das Feld "%s" ist leer.', $field));
        }

        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw new UnexpectedValueException(
                sprintf('Das Feld "%s" ist länger als %d Zeichen.', $field, self::MAX_LENGTH),
            );
        }

        return $value;
    }

    /**
     * Returns the raw form of an undecodable message as it was received.
     *
     * @param MessageDecodingFailedException $failure undecodable message
     *
     * @return array{body: string, headers?: array<string, string>} raw message for the transport
     */
    private static function originalEncodedEnvelope(MessageDecodingFailedException $failure): array
    {
        $body = $failure->encodedEnvelope['body'] ?? null;
        $headers = [];

        foreach ((array) ($failure->encodedEnvelope['headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return ['body' => is_string($body) ? $body : '', 'headers' => $headers];
    }
}
