<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Deletion;

use stdClass;
use JsonException;
use LogicException;
use UnexpectedValueException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Transport-Serializer für Löschaufträge (deletion.requested): dekodiert den rohen JSON-Body der Nachricht
 * (Payload id und ticketId). Ein strukturell ungültiger Auftrag wird als nicht dekodierbar gemeldet; Symfony
 * Messenger lehnt ihn dann ohne Wiederholung ab (reject ohne Requeue) und protokolliert die Ablehnung mit dem hier
 * genannten Grund. Der Grund nennt das betroffene Feld, nie einen Wert.
 */
final readonly class DeletionRequestSerializer implements SerializerInterface
{
    private const int MAX_LENGTH = 255;
    private const string REJECTION_PREFIX = 'Strukturell ungültiger Löschauftrag: ';
    private const string JSON_WHITESPACE = " \t\n\r";

    /**
     * Dekodiert den Löschauftrag. Strukturell ungültig ist er, wenn das Payload leer, kein gültiges JSON oder kein
     * JSON-Objekt ist oder wenn id oder ticketId fehlt, leer ist, kein String ist oder länger als 255 Zeichen ist.
     * Unbekannte zusätzliche Felder werden ignoriert.
     *
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope rohe Nachricht des Transports
     *
     * @return Envelope der Löschauftrag oder, bei einem strukturell ungültigen Auftrag, die nicht dekodierbare
     *                  Nachricht als MessageDecodingFailedException
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
     * Kodiert einen Löschauftrag als JSON-Body mit id und ticketId. Eine nicht dekodierbare Nachricht behält ihre
     * ursprüngliche rohe Form.
     *
     * @param Envelope $envelope Löschauftrag oder nicht dekodierbare Nachricht
     *
     * @return array{body: string, headers?: array<string, string>} rohe Nachricht für den Transport
     *
     * @throws LogicException wenn die Nachricht kein Löschauftrag ist
     * @throws JsonException  wenn sich der Löschauftrag nicht als JSON kodieren lässt
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
     * Dekodiert den Body als JSON-Objekt. Als leer gilt auch ein Body, der nur aus Leerraum nach JSON besteht.
     *
     * @param string $body roher Body der Nachricht
     *
     * @return array<array-key, mixed> Felder des JSON-Objekts
     *
     * @throws UnexpectedValueException wenn der Body leer, kein gültiges JSON oder kein JSON-Objekt ist
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
     * Liest ein Pflichtfeld des Payloads.
     *
     * @param array<array-key, mixed> $payload Felder des JSON-Objekts
     * @param string                  $field   Name des Feldes
     *
     * @return string der Wert des Feldes
     *
     * @throws UnexpectedValueException wenn das Feld fehlt, kein String, leer oder länger als 255 Zeichen ist
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
     * Liefert die rohe Form einer nicht dekodierbaren Nachricht, wie sie empfangen wurde.
     *
     * @param MessageDecodingFailedException $failure nicht dekodierbare Nachricht
     *
     * @return array{body: string, headers?: array<string, string>} rohe Nachricht für den Transport
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
