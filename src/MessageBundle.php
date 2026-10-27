<?php

declare(strict_types=1);

namespace F3lix\MessageBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Messaging-Infrastruktur für Symfony-Microservices: Transactional Outbox, Anbindung an RabbitMQ, Retry beim
 * Konsum, Löschaufträge und Logging über AMQP.
 */
final class MessageBundle extends Bundle
{
}
