<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Testing;

use JsonException;
use PHPUnit\Framework\Assert;

/**
 * Assertions für das Event-Format: Felder und ihre Reihenfolge, Timestamp, Aufbau der
 * delta-Einträge einschließlich Maskierung. Geprüft wird der Body, wie er an RabbitMQ geht.
 */
trait EventFormatAssertions
{
    /** UTC nach RFC 3339 mit Millisekunden und Suffix Z */
    private const string TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    /**
     * Prüft einen Zeitpunkt im Format YYYY-MM-DDTHH:mm:ss.SSSZ.
     *
     * @param mixed  $value Wert aus dem Payload
     * @param string $field Name des Feldes für die Meldung
     *
     * @return void
     */
    public static function assertEventTimestamp(mixed $value, string $field = 'timestamp'): void
    {
        Assert::assertIsString($value, sprintf('%s ist kein String.', $field));
        Assert::assertMatchesRegularExpression(
            self::TIMESTAMP_PATTERN,
            $value,
            sprintf('%s ist nicht im Format YYYY-MM-DDTHH:mm:ss.SSSZ.', $field),
        );
    }

    /**
     * Prüft <entität>.created: genau id, actorId, timestamp in dieser Reihenfolge.
     *
     * @param string $body Body der Nachricht
     *
     * @return array<string, mixed> das Payload
     */
    public static function assertCreatedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'actorId', 'timestamp'], array_keys($payload), 'Felder von created');
        self::assertNonEmptyString($payload['id'], 'id');
        self::assertNonEmptyString($payload['actorId'], 'actorId');
        self::assertEventTimestamp($payload['timestamp']);

        return $payload;
    }

    /**
     * Prüft <entität>.updated: genau id, actorId, timestamp, delta in dieser Reihenfolge, delta nicht leer und
     * jeder Eintrag wie unter assertDeltaEntry beschrieben.
     *
     * @param string $body Body der Nachricht
     *
     * @return array<string, mixed> das Payload
     */
    public static function assertUpdatedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'actorId', 'timestamp', 'delta'], array_keys($payload), 'Felder von updated');
        self::assertNonEmptyString($payload['id'], 'id');
        self::assertNonEmptyString($payload['actorId'], 'actorId');
        self::assertEventTimestamp($payload['timestamp']);
        Assert::assertIsList($payload['delta'], 'delta ist keine Liste.');
        Assert::assertNotEmpty($payload['delta'], 'delta ist leer.');

        foreach ($payload['delta'] as $entry) {
            self::assertDeltaEntry($entry);
        }

        return $payload;
    }

    /**
     * Prüft einen Eintrag in delta: field und operation; ohne Maskierung oldValue und newValue, bei added oldValue
     * null, bei removed newValue null; bei einer maskierten Änderung eines Feldes (changed) nur field, operation und
     * masked: true.
     *
     * @param mixed $entry Eintrag aus delta
     *
     * @return void
     */
    public static function assertDeltaEntry(mixed $entry): void
    {
        Assert::assertIsArray($entry, 'Ein Eintrag in delta ist kein Objekt.');
        self::assertNonEmptyString($entry['field'] ?? null, 'field');
        Assert::assertContains($entry['operation'] ?? null, ['changed', 'added', 'removed'], 'operation');

        $masked = array_key_exists('masked', $entry);

        if ($masked) {
            Assert::assertTrue($entry['masked'], 'masked ist nicht true.');
        }

        if ($masked && $entry['operation'] === 'changed') {
            Assert::assertSame(['field', 'operation', 'masked'], array_keys($entry), 'Felder eines maskierten Eintrags');

            return;
        }

        $keys = ['field', 'operation', 'oldValue', 'newValue'];
        Assert::assertSame($masked ? [...$keys, 'masked'] : $keys, array_keys($entry), 'Felder eines Eintrags in delta');

        if ($entry['operation'] === 'added') {
            Assert::assertNull($entry['oldValue'], 'oldValue ist bei added nicht null.');
        }

        if ($entry['operation'] === 'removed') {
            Assert::assertNull($entry['newValue'], 'newValue ist bei removed nicht null.');
        }
    }

    /**
     * Prüft <entität>.deleted: genau id, timestamp, ticketId in dieser Reihenfolge.
     *
     * @param string $body Body der Nachricht
     *
     * @return array<string, mixed> das Payload
     */
    public static function assertDeletedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'timestamp', 'ticketId'], array_keys($payload), 'Felder von deleted');
        self::assertFeedbackFields($payload);

        return $payload;
    }

    /**
     * Prüft <entität>.deletion.failed: genau id, timestamp, ticketId, reason in dieser Reihenfolge.
     *
     * @param string $body Body der Nachricht
     *
     * @return array<string, mixed> das Payload
     */
    public static function assertDeletionFailedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'timestamp', 'ticketId', 'reason'], array_keys($payload), 'Felder von deletion.failed');
        self::assertFeedbackFields($payload);
        self::assertNonEmptyString($payload['reason'], 'reason');

        return $payload;
    }

    /**
     * Prüft <gegenstand>.deletion.processed: zuerst id, timestamp, ticketId; servicespezifische Felder folgen.
     *
     * @param string $body Body der Nachricht
     *
     * @return array<string, mixed> das Payload
     */
    public static function assertDeletionProcessedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(
            ['id', 'timestamp', 'ticketId'],
            array_slice(array_keys($payload), 0, 3),
            'Erste Felder von deletion.processed',
        );
        self::assertFeedbackFields($payload);

        return $payload;
    }

    /**
     * Prüft die gemeinsamen Felder einer Rückmeldung zu einem Löschauftrag; actorId gehört nicht dazu.
     *
     * @param array<string, mixed> $payload Payload der Rückmeldung
     *
     * @return void
     */
    private static function assertFeedbackFields(array $payload): void
    {
        self::assertNonEmptyString($payload['id'], 'id');
        self::assertEventTimestamp($payload['timestamp']);
        self::assertNonEmptyString($payload['ticketId'], 'ticketId');
        Assert::assertArrayNotHasKey('actorId', $payload, 'Eine Rückmeldung trägt kein actorId.');
    }

    /**
     * Prüft, dass ein Wert ein nicht leerer String ist.
     *
     * @param mixed  $value Wert
     * @param string $field Name des Feldes für die Meldung
     *
     * @return void
     */
    private static function assertNonEmptyString(mixed $value, string $field): void
    {
        Assert::assertIsString($value, sprintf('%s ist kein String.', $field));
        Assert::assertNotSame('', $value, sprintf('%s ist leer.', $field));
    }

    /**
     * Dekodiert den Body als JSON-Objekt; die Reihenfolge der Felder bleibt erhalten.
     *
     * @param string $body Body der Nachricht
     *
     * @return array<string, mixed> das Payload
     */
    private static function decodeEventBody(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Assert::fail('Der Body ist kein gültiges JSON: ' . $exception->getMessage());
        }

        Assert::assertIsArray($payload, 'Der Body ist kein JSON-Objekt.');
        Assert::assertFalse(array_is_list($payload) && $payload !== [], 'Der Body ist kein JSON-Objekt.');

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
