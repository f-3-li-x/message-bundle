<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox\Entity;

/**
 * Status eines Outbox-Eintrags: ausstehend oder nach zehn gezählten Fehlversuchen fehlgeschlagen.
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
}
