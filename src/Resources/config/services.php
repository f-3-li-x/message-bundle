<?php

declare(strict_types=1);

use ACAT\MessageBundle\Message\BodySerializer;
use ACAT\MessageBundle\Logging\AmqpLogHandler;
use ACAT\MessageBundle\Connection\RabbitMqConfig;
use ACAT\MessageBundle\Consumer\RetryingExecutor;
use ACAT\MessageBundle\Logging\AmqpExchangeFactory;
use ACAT\MessageBundle\Deletion\DeletionRequestSerializer;
use ACAT\MessageBundle\Connection\RabbitMqTransportFactory;
use ACAT\MessageBundle\DependencyInjection\MessageExtension;
use ACAT\MessageBundle\Logging\AmqpExchangeFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Verbindung, Nachrichtenformat, Retry beim Konsum, Löschaufträge und Logging. Die Verbindungsdaten kommen aus
 * den Variablen RABBITMQ_*; RABBITMQ_HEARTBEAT und RABBITMQ_RETRY haben Standardwerte.
 */
return static function (ContainerConfigurator $configurator): void {
    $configurator->parameters()
        ->set('message.default.rabbitmq_heartbeat', 60)
        ->set('message.default.rabbitmq_retry', 10);

    $services = $configurator->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(RabbitMqConfig::class)
        ->args([
            '%env(RABBITMQ_HOST)%',
            '%env(int:RABBITMQ_PORT)%',
            '%env(RABBITMQ_USER)%',
            '%env(RABBITMQ_PASSWORD)%',
            '%env(RABBITMQ_VHOST)%',
            '%env(bool:RABBITMQ_SECURE)%',
            '%env(default:message.default.rabbitmq_heartbeat:int:RABBITMQ_HEARTBEAT)%',
        ]);

    $services->set(RabbitMqTransportFactory::class)
        ->args([service(RabbitMqConfig::class), service('messenger.transport.amqp.factory')])
        ->tag('messenger.transport_factory');

    $services->set(BodySerializer::class);

    $services->set(DeletionRequestSerializer::class);

    $services->set(RetryingExecutor::class)
        ->arg('$maxAttempts', '%env(default:message.default.rabbitmq_retry:int:RABBITMQ_RETRY)%');

    $services->set(AmqpExchangeFactory::class)
        ->arg('$exchangeName', '%' . MessageExtension::PARAMETER_SERVICE . '%');

    $services->alias(AmqpExchangeFactoryInterface::class, AmqpExchangeFactory::class);

    $services->set(MessageExtension::AMQP_LOG_HANDLER, AmqpLogHandler::class)
        ->args([
            '$service'   => '%' . MessageExtension::PARAMETER_SERVICE . '%',
            '$heartbeat' => '%env(default:message.default.rabbitmq_heartbeat:int:RABBITMQ_HEARTBEAT)%',
            '$enabled'   => '%' . MessageExtension::PARAMETER_LOGGING_ENABLED . '%',
        ]);
};
