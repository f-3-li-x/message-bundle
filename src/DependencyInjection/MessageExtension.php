<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\DependencyInjection;

use Exception;
use ReflectionClass;
use Symfony\Component\Config\FileLocator;
use ACAT\MessageBundle\Message\BodySerializer;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;
use ACAT\MessageBundle\Persistence\UtcDateTimeMsType;
use ACAT\MessageBundle\Connection\RabbitMqTransportFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ACAT\MessageBundle\Logging\SensitiveFieldRedactionProcessor;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * Loads the services of the bundle and prepends the configuration of Symfony Messenger and Doctrine: default bus
 * without reject_redelivered_message_middleware, transports for publishing and consuming, mapping of the outbox
 * table.
 *
 * @phpstan-type BundleConfig array{
 *     service: string,
 *     outbox: array{
 *         enabled: bool,
 *         central_exchange: string|null,
 *         central_event_types: list<string>,
 *         command_namespace: string,
 *     },
 *     consumers: array<string, array{queue: string, serializer: string}>,
 *     logging: array{enabled: bool, redact_fields: list<string>},
 * }
 */
final class MessageExtension extends Extension implements PrependExtensionInterface
{
    public const string AMQP_LOG_HANDLER = 'message.logging.amqp_handler';
    public const string PARAMETER_SERVICE = 'message.service';
    public const string PARAMETER_CENTRAL_EXCHANGE = 'message.outbox.central_exchange';
    public const string PARAMETER_CENTRAL_EVENT_TYPES = 'message.outbox.central_event_types';
    public const string PARAMETER_COMMAND_NAMESPACE = 'message.outbox.command_namespace';
    public const string PARAMETER_LOGGING_ENABLED = 'message.logging.enabled';

    /** Environment in which all transports run in memory and the AmqpLogHandler is disabled */
    private const string TEST_ENVIRONMENT = 'test';

    private const string IN_MEMORY_DSN = 'in-memory://';

    /** Seconds the outbox publisher waits per message for the broker's confirmation */
    private const int CONFIRM_TIMEOUT = 10;

    /**
     * Default bus of Symfony 8 without reject_redelivered_message_middleware: it would reject every message that
     * RabbitMQ redelivers before the event consumer runs; with max_retries: 0, a message whose worker stopped before
     * the ack would end up in the dead-letter queue without being processed.
     *
     * @var list<string>
     */
    private const array MIDDLEWARE = [
        'add_default_stamps_middleware',
        'add_bus_name_stamp_middleware',
        'dispatch_after_current_bus',
        'decode_failed_message_middleware',
        'failed_message_processing_middleware',
        'send_message',
        'handle_message',
    ];

    /**
     * Prepends the configuration of framework.messenger and doctrine.
     *
     * @param ContainerBuilder $container container of the application
     *
     * @return void
     */
    public function prepend(ContainerBuilder $container): void
    {
        $config = $this->bundleConfig($container->getExtensionConfig($this->getAlias()));
        $test = self::environment($container) === self::TEST_ENVIRONMENT;

        $container->prependExtensionConfig('framework', ['messenger' => self::messengerConfig($config, $test)]);

        if ($config['outbox']['enabled'] && $container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', self::doctrineConfig());
        }
    }

    /**
     * Loads the services of the bundle.
     *
     * @param array<array-key, mixed> $configs   configurations under the key message
     * @param ContainerBuilder        $container container of the application
     *
     * @return void
     *
     * @throws Exception if a file with service definitions cannot be loaded
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->bundleConfig($configs);
        $test = self::environment($container) === self::TEST_ENVIRONMENT;

        $container->setParameter(self::PARAMETER_SERVICE, $config['service']);
        $container->setParameter(self::PARAMETER_CENTRAL_EXCHANGE, $config['outbox']['central_exchange']);
        $container->setParameter(self::PARAMETER_CENTRAL_EVENT_TYPES, $config['outbox']['central_event_types']);
        $container->setParameter(self::PARAMETER_COMMAND_NAMESPACE, $config['outbox']['command_namespace']);
        $container->setParameter(self::PARAMETER_LOGGING_ENABLED, $config['logging']['enabled'] && !$test);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        if ($config['outbox']['enabled']) {
            $loader->load('outbox.php');
        }

        if ($config['logging']['redact_fields'] !== []) {
            $container->register(SensitiveFieldRedactionProcessor::class, SensitiveFieldRedactionProcessor::class)
                ->setArguments([$config['logging']['redact_fields']])
                ->addTag('monolog.processor');
        }
    }

    /**
     * Returns the transports for publishing: one for the service's exchange and, if configured, one for the central
     * exchange. The name of a transport is the name of its exchange in snake_case.
     *
     * @param string      $service         name of the service, also the name of its exchange
     * @param string|null $centralExchange central exchange or null
     *
     * @return array<string, string> exchange per transport, e.g. ['person_service' => 'person-service']
     */
    public static function publishTransports(string $service, ?string $centralExchange): array
    {
        $exchanges = $centralExchange === null ? [$service] : [$centralExchange, $service];
        $transports = [];

        foreach ($exchanges as $exchange) {
            $transports[str_replace(['-', '.'], '_', $exchange)] = $exchange;
        }

        return $transports;
    }

    /**
     * Processes the configurations under the key message.
     *
     * @param array<array-key, mixed> $configs configurations of the application
     *
     * @return BundleConfig the processed configuration
     */
    private function bundleConfig(array $configs): array
    {
        /** @var BundleConfig $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        return $config;
    }

    /**
     * Builds the configuration of framework.messenger.
     *
     * @param BundleConfig $config processed configuration of the bundle
     * @param bool         $test   true in the test environment; all transports then run in memory
     *
     * @return array<string, mixed> the configuration under framework.messenger
     */
    private static function messengerConfig(array $config, bool $test): array
    {
        $dsn = $test ? self::IN_MEMORY_DSN : RabbitMqTransportFactory::DSN;
        $transports = [];

        if ($config['outbox']['enabled']) {
            $exchanges = self::publishTransports($config['service'], $config['outbox']['central_exchange']);

            foreach ($exchanges as $transport => $exchange) {
                $transports[$transport] = [
                    'dsn'        => $dsn,
                    'serializer' => BodySerializer::class,
                    'options'    => [
                        'auto_setup'      => false,
                        'confirm_timeout' => self::CONFIRM_TIMEOUT,
                        'queues'          => [],
                        'exchange'        => ['name' => $exchange, 'type' => 'topic'],
                    ],
                ];
            }
        }

        foreach ($config['consumers'] as $transport => $consumer) {
            $transports[$transport] = [
                'dsn'            => $dsn,
                'serializer'     => $consumer['serializer'],
                'options'        => [
                    'auto_setup' => false,
                    'queues'     => [$consumer['queue'] => null],
                ],
                'retry_strategy' => ['max_retries' => 0],
            ];
        }

        return [
            'buses'      => [
                'messenger.bus.default' => [
                    'default_middleware' => ['enabled' => false],
                    'middleware'         => self::MIDDLEWARE,
                ],
            ],
            'transports' => $transports,
        ];
    }

    /**
     * Builds the configuration of doctrine: custom type for timestamps and mapping of OutboxEntry.
     *
     * @return array<string, mixed> the configuration under doctrine
     */
    private static function doctrineConfig(): array
    {
        $reflection = new ReflectionClass(OutboxEntry::class);

        return [
            'dbal' => ['types' => [UtcDateTimeMsType::NAME => UtcDateTimeMsType::class]],
            'orm'  => [
                'mappings' => [
                    'MessageBundle' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => dirname((string) $reflection->getFileName()),
                        'prefix'    => $reflection->getNamespaceName(),
                        'alias'     => 'MessageBundle',
                    ],
                ],
            ],
        ];
    }

    /**
     * Returns the environment of the kernel.
     *
     * @param ContainerBuilder $container container of the application
     *
     * @return string the environment, e.g. prod or test; prod if it is unknown
     */
    private static function environment(ContainerBuilder $container): string
    {
        if (!$container->hasParameter('kernel.environment')) {
            return 'prod';
        }

        $environment = $container->getParameter('kernel.environment');

        return is_string($environment) ? $environment : 'prod';
    }
}
