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
 * Custom type message_utc_datetime_ms: stores points in time in UTC with milliseconds in columns of type DATETIME(3).
 * The mapping sets the precision via columnDefinition (COLUMN_DEFINITION): DBAL knows no fractional seconds for
 * timestamps and does not read them from the database when comparing schemas.
 */
final class UtcDateTimeMsType extends Type
{
    public const string NAME = 'message_utc_datetime_ms';
    public const string COLUMN_DEFINITION = 'DATETIME(3) NOT NULL';

    private const string WRITE_FORMAT = 'Y-m-d H:i:s.v';

    /** @var list<string> formats in which the database returns the value, with and without fractional seconds */
    private const array READ_FORMATS = ['Y-m-d H:i:s.u', 'Y-m-d H:i:s'];

    /**
     * Returns the column declaration of the database platform for timestamps.
     *
     * @param array<string, mixed> $column   column definition from the mapping
     * @param AbstractPlatform     $platform database platform
     *
     * @return string the column declaration
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTypeDeclarationSQL($column);
    }

    /**
     * Turns the stored value into a point in time in UTC.
     *
     * @param mixed            $value    value from the database
     * @param AbstractPlatform $platform database platform
     *
     * @return DateTimeImmutable|null the point in time in UTC with milliseconds, or null
     *
     * @throws InvalidType   if the value is neither null nor a string
     * @throws InvalidFormat if the string does not have the format of the column
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
     * Turns the point in time into the value for the database: converted to UTC, cut to milliseconds.
     *
     * @param mixed            $value    point in time or null
     * @param AbstractPlatform $platform database platform
     *
     * @return string|null the point in time in the format YYYY-MM-DD HH:mm:ss.SSS, or null
     *
     * @throws InvalidType if the value is neither null nor a DateTimeImmutable
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
