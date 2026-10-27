<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Logging;

use UnitEnum;
use Throwable;
use JsonSerializable;
use DateTimeInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Hält sensible Daten aus den Logs: ersetzt in context und extra jedes Log-Eintrags, in beliebiger Tiefe, die
 * Werte der als sensibel eingestuften Felder durch ***REDACTED***, bevor der Eintrag einen Handler erreicht. Wird
 * für alle Channels registriert, sobald message.logging.redact_fields Felder nennt.
 */
final readonly class SensitiveFieldRedactionProcessor implements ProcessorInterface
{
    public const string REDACTED = '***REDACTED***';

    /** Tiefe, bis zu der ein Eintrag durchsucht wird; schützt vor Objekten, die aufeinander verweisen */
    private const int MAX_DEPTH = 10;

    /** @var array<array-key, true> die sensiblen Felder als Schlüssel */
    private array $sensitiveFields;

    /**
     * Erzeugt den Processor.
     *
     * @param list<string> $sensitiveFields Namen der als sensibel eingestuften Felder
     */
    public function __construct(array $sensitiveFields)
    {
        $this->sensitiveFields = array_fill_keys($sensitiveFields, true);
    }

    /**
     * Liefert den Eintrag mit ersetzten sensiblen Feldern in context und extra.
     *
     * @param LogRecord $record Log-Eintrag
     *
     * @return LogRecord der Eintrag ohne sensible Werte
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context, 1),
            extra: $this->redact($record->extra, 1),
        );
    }

    /**
     * Ersetzt den Wert jedes sensiblen Schlüssels und durchsucht die übrigen Werte eine Ebene tiefer.
     *
     * @param array<array-key, mixed> $data  Werte einer Ebene
     * @param int                     $depth Tiefe im Log-Eintrag; context und extra liegen auf Tiefe 1
     *
     * @return array<array-key, mixed> die Werte ohne sensible Werte
     */
    private function redact(array $data, int $depth): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = isset($this->sensitiveFields[$key])
                ? self::REDACTED
                : $this->redactNested($value, $depth + 1);
        }

        return $data;
    }

    /**
     * Durchsucht ein Array oder Objekt nach sensiblen Feldern. Exceptions, Zeitpunkte, Enum-Fälle und Werte, die
     * weder Array noch Objekt sind, bleiben unverändert; was tiefer als die höchste Tiefe liegt, wird als Ganzes
     * ersetzt. Ein Objekt wird über das durchsucht, was ein Formatter von ihm schreiben würde (seine
     * JSON-Darstellung oder seine öffentlichen Eigenschaften), und nur ersetzt, wenn darin ein sensibles Feld liegt.
     *
     * @param mixed $value Wert
     * @param int   $depth Tiefe des Werts im Log-Eintrag
     *
     * @return mixed der Wert ohne sensible Werte
     */
    private function redactNested(mixed $value, int $depth): mixed
    {
        if (
            !is_array($value)
            && (
                !is_object($value)
                || $value instanceof Throwable
                || $value instanceof DateTimeInterface
                || $value instanceof UnitEnum
            )
        ) {
            return $value;
        }

        if ($depth > self::MAX_DEPTH) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            return $this->redact($value, $depth);
        }

        try {
            $fields = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        } catch (Throwable) {
            return $value;
        }

        if (!is_array($fields)) {
            return $value;
        }

        $redacted = $this->redact($fields, $depth);

        return $redacted === $fields ? $value : [$value::class => $redacted];
    }
}
