<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

/**
 * Result of a run of the outbox publisher.
 */
final readonly class PublishResult
{
    /**
     * Holds the result of a run.
     *
     * @param int  $delivered number of delivered and deleted entries
     * @param int  $rejected  number of counted failed attempts (message rejected or not confirmed)
     * @param int  $skipped   number of pending entries skipped because of an earlier undelivered or failed entry
     *                        of the same aggregate_id
     * @param bool $aborted   true if the run was aborted because RabbitMQ is unreachable
     */
    public function __construct(
        public int $delivered,
        public int $rejected,
        public int $skipped,
        public bool $aborted,
    ) {
    }
}
