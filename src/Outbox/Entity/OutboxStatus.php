<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox\Entity;

/**
 * Status of an outbox entry: pending, or failed after ten counted failed attempts.
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
}
