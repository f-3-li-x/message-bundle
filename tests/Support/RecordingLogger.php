<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Support;

use Stringable;
use Psr\Log\AbstractLogger;

/**
 * Logger that records the entries.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * Records an entry.
     *
     * @param mixed                   $level   level
     * @param string|Stringable       $message message
     * @param array<array-key, mixed> $context context
     *
     * @return void
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Returns the levels of the recorded entries.
     *
     * @return list<mixed> the levels in the order of the entries
     */
    public function levels(): array
    {
        return array_column($this->records, 'level');
    }
}
