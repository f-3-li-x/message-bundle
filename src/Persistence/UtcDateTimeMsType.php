<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Persistence;

use DateTimeZone;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * Custom Type message_utc_datetime_ms: speichert Zeitstempel in UTC mit Millisekunden in Spalten vom Typ
 * DATETIME(3). Die Genauigkeit legt das Mapping über columnDefinition fest (COLUMN_DEFINITION): DBAL kennt bei
 * Zeitstempeln keine Nachkommastellen und liest sie beim Schemavergleich nicht aus der Datenbank.
 */
final class UtcDateTimeMsType extends Type
{
    public const string NAME = 'message_utc_datetime_ms';
    public const string COLUMN_DEFINITION = 'DATETIME(3) NOT NULL';

    private const string WRITE_FORMAT = 'Y-m-d H:i:s.v';

    /** @var list<string> Formate, in denen die Datenbank den Wert liefert, mit und ohne Nachkommastellen */
    private const array READ_FORMATS = ['Y-m-d H:i:s.u', 'Y-m-d H:i:s'];

    /**
     * Liefert die Spaltendeklaration der Datenbankplattform für Zeitstempel.
     *
     * @param array<string, mixed> $column   Spaltendefinition aus dem Mapping
     * @param AbstractPlatform     $platform Datenbankplattform
     *
     * @return string die Spaltendeklaration
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTypeDeclarationSQL($column);
    }

    /**
     * Bildet aus dem gespeicherten Wert den Zeitstempel in UTC.
     *
     * @param mixed            $value    Wert aus der Datenbank
     * @param AbstractPlatform $platform Datenbankplattform
     *
     * @return DateTimeImmutable|null der Zeitstempel in UTC mit Millisekunden oder null
     *
     * @throws InvalidType   wenn der Wert weder null noch eine Zeichenkette ist
     * @throws InvalidFormat wenn die Zeichenkette nicht das Format der Spalte hat
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string', DateTimeImmutable::class]);
        }

        foreach (self::READ_FORMATS as $format) {
            $timestamp = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));

            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        throw InvalidFormat::new($value, self::class, self::READ_FORMATS[0]);
    }

    /**
     * Bildet aus dem Zeitstempel den Wert für die Datenbank: nach UTC umgerechnet, auf Millisekunden gekürzt.
     *
     * @param mixed            $value    Zeitstempel oder null
     * @param AbstractPlatform $platform Datenbankplattform
     *
     * @return string|null der Zeitstempel im Format YYYY-MM-DD HH:mm:ss.SSS oder null
     *
     * @throws InvalidType wenn der Wert weder null noch ein DateTimeImmutable ist
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof DateTimeImmutable) {
            throw InvalidType::new($value, self::class, ['null', DateTimeImmutable::class]);
        }

        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::WRITE_FORMAT);
    }
}
