<?php

declare(strict_types=1);

use F3lix\MessageBundle\Outbox\OutboxSender;
use F3lix\MessageBundle\Outbox\OutboxWriter;
use F3lix\MessageBundle\Outbox\OutboxPublisher;
use F3lix\MessageBundle\Outbox\ExchangeResolver;
use F3lix\MessageBundle\Outbox\OutboxEntryStore;
use F3lix\MessageBundle\Outbox\MessengerOutboxSender;
use F3lix\MessageBundle\Outbox\DoctrineOutboxEntryStore;
use F3lix\MessageBundle\Outbox\Command\PublishOutboxCommand;
use F3lix\MessageBundle\DependencyInjection\MessageExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use F3lix\MessageBundle\Outbox\Command\RetryFailedOutboxCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Transactional Outbox: Writer, Zugriff auf die Tabelle outbox_entries, Übergabe an RabbitMQ über die
 * Messenger-Transports je Exchange, Publisher und die Konsolenkommandos im konfigurierten Namensraum.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $container): void {
    $service = $container->getParameter(MessageExtension::PARAMETER_SERVICE);
    $centralExchange = $container->getParameter(MessageExtension::PARAMETER_CENTRAL_EXCHANGE);
    $namespace = $container->getParameter(MessageExtension::PARAMETER_COMMAND_NAMESPACE);

    $senders = [];
    $transports = MessageExtension::publishTransports(
        is_string($service) ? $service : '',
        is_string($centralExchange) ? $centralExchange : null,
    );

    foreach ($transports as $transport => $exchange) {
        $senders[$exchange] = service('messenger.transport.' . $transport);
    }

    $publishCommand = (is_string($namespace) ? $namespace : 'outbox') . ':publish';
    $retryFailedCommand = (is_string($namespace) ? $namespace : 'outbox') . ':retry-failed';

    $services = $configurator->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(ExchangeResolver::class)
        ->args([
            '%' . MessageExtension::PARAMETER_SERVICE . '%',
            '%' . MessageExtension::PARAMETER_CENTRAL_EXCHANGE . '%',
            '%' . MessageExtension::PARAMETER_CENTRAL_EVENT_TYPES . '%',
        ]);

    $services->set(OutboxWriter::class);

    $services->set(DoctrineOutboxEntryStore::class);
    $services->alias(OutboxEntryStore::class, DoctrineOutboxEntryStore::class);

    $services->set(MessengerOutboxSender::class)
        ->arg('$senders', $senders)
        ->arg('$appId', '%' . MessageExtension::PARAMETER_SERVICE . '%');
    $services->alias(OutboxSender::class, MessengerOutboxSender::class);

    $services->set(OutboxPublisher::class);

    $services->set(PublishOutboxCommand::class)
        ->autoconfigure(false)
        ->arg('$name', $publishCommand)
        ->tag('console.command', ['command' => $publishCommand]);

    $services->set(RetryFailedOutboxCommand::class)
        ->autoconfigure(false)
        ->arg('$name', $retryFailedCommand)
        ->tag('console.command', ['command' => $retryFailedCommand]);
};
