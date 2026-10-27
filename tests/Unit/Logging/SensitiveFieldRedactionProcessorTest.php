<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Logging;

use stdClass;
use Monolog\Level;
use RuntimeException;
use DateTimeImmutable;
use Monolog\LogRecord;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use ACAT\MessageBundle\Logging\SensitiveFieldRedactionProcessor;

final class SensitiveFieldRedactionProcessorTest extends TestCase
{
    private SensitiveFieldRedactionProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new SensitiveFieldRedactionProcessor(['firstName', 'value']);
    }

    public function testRedactsSensitiveFieldsInContextAndExtraAtAnyDepth(): void
    {
        $record = ($this->processor)(self::record(
            ['id' => 'x', 'firstName' => 'Erika', 'contact' => ['value' => '0421 123', 'contactType' => 'phone']],
            ['firstName' => 'Erika'],
        ));

        self::assertSame(
            ['id' => 'x', 'firstName' => '***REDACTED***', 'contact' => ['value' => '***REDACTED***', 'contactType' => 'phone']],
            $record->context,
        );
        self::assertSame(['firstName' => '***REDACTED***'], $record->extra);
    }

    public function testSearchesObjectsThroughTheirSerializedForm(): void
    {
        $object = new stdClass();
        $object->firstName = 'Erika';
        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['value' => 'secret'];
            }
        };

        $record = ($this->processor)(self::record(['person' => $object, 'contact' => $serializable]));

        self::assertSame([stdClass::class => ['firstName' => '***REDACTED***']], $record->context['person']);
        self::assertIsArray($record->context['contact']);
        self::assertSame(['value' => '***REDACTED***'], array_values($record->context['contact'])[0]);
    }

    public function testKeepsExceptionsTimesAndObjectsWithoutSensitiveFields(): void
    {
        $exception = new RuntimeException('failure');
        $time = new DateTimeImmutable();
        $object = new stdClass();
        $object->id = 'x';

        $record = ($this->processor)(self::record(['exception' => $exception, 'time' => $time, 'object' => $object]));

        self::assertSame($exception, $record->context['exception']);
        self::assertSame($time, $record->context['time']);
        self::assertSame($object, $record->context['object']);
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private static function record(array $context, array $extra = []): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'entry', $context, $extra);
    }
}
