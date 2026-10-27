<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Support;

use Stringable;
use Psr\Log\AbstractLogger;

/**
 * Logger, der die Einträge aufzeichnet.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * Zeichnet einen Eintrag auf.
     *
     * @param mixed                   $level   Level
     * @param string|Stringable       $message Meldung
     * @param array<array-key, mixed> $context Context
     *
     * @return void
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Liefert die Level der aufgezeichneten Einträge.
     *
     * @return list<mixed> die Level in der Reihenfolge der Einträge
     */
    public function levels(): array
    {
        return array_column($this->records, 'level');
    }
}
