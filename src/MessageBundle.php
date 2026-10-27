<?php

declare(strict_types=1);

namespace ACAT\MessageBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Messaging infrastructure for Symfony microservices: transactional outbox, RabbitMQ connection, retries when
 * consuming, deletion requests and logging over AMQP.
 */
final class MessageBundle extends Bundle
{
}
