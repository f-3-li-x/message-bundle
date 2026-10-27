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
 * Lädt die Services des Bundles und belegt die Konfiguration von Symfony Messenger und Doctrine vor: Standardbus
 * ohne reject_redelivered_message_middleware, Transports zum Veröffentlichen und Konsumieren, Mapping der
 * Outbox-Tabelle.
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

    /** Umgebung, in der alle Transports In-Memory laufen und der AmqpLogHandler abgeschaltet ist */
    private const string TEST_ENVIRONMENT = 'test';

    private const string IN_MEMORY_DSN = 'in-memory://';

    /** Zeit in Sekunden, die der Outbox-Publisher je Nachricht auf die Bestätigung des Brokers wartet */
    private const int CONFIRM_TIMEOUT = 10;

    /**
     * Standardbus von Symfony 8 ohne reject_redelivered_message_middleware: Diese würde jede von RabbitMQ erneut
     * zugestellte Nachricht ablehnen, bevor der Event-Consumer läuft; wegen max_retries: 0 landete eine Nachricht,
     * deren Worker vor dem ack abgebrochen ist, ohne Verarbeitung in der Dead-Letter-Queue.
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
     * Belegt die Konfiguration von framework.messenger und doctrine vor.
     *
     * @param ContainerBuilder $container Container der Anwendung
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
     * Lädt die Services des Bundles.
     *
     * @param array<array-key, mixed> $configs   Konfigurationen unter dem Schlüssel message
     * @param ContainerBuilder        $container Container der Anwendung
     *
     * @return void
     *
     * @throws Exception wenn sich eine Datei mit Service-Definitionen nicht laden lässt
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
     * Liefert die Transports zum Veröffentlichen: einen für den Exchange des Service und, sofern konfiguriert, einen
     * für den zentralen Exchange. Der Name eines Transports ist der Name seines Exchange in snake_case.
     *
     * @param string      $service         Name des Service, zugleich Name seines Exchange
     * @param string|null $centralExchange zentraler Exchange oder null
     *
     * @return array<string, string> Exchange je Transport, z. B. ['person_service' => 'person-service']
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
     * Verarbeitet die Konfigurationen unter dem Schlüssel message.
     *
     * @param array<array-key, mixed> $configs Konfigurationen der Anwendung
     *
     * @return BundleConfig die verarbeitete Konfiguration
     */
    private function bundleConfig(array $configs): array
    {
        /** @var BundleConfig $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        return $config;
    }

    /**
     * Bildet die Konfiguration von framework.messenger.
     *
     * @param BundleConfig $config verarbeitete Konfiguration des Bundles
     * @param bool         $test   true in der Umgebung test; dann laufen alle Transports In-Memory
     *
     * @return array<string, mixed> die Konfiguration unter framework.messenger
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
     * Bildet die Konfiguration von doctrine: Custom Type für Zeitstempel und Mapping von OutboxEntry.
     *
     * @return array<string, mixed> die Konfiguration unter doctrine
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
     * Liefert die Umgebung des Kernels.
     *
     * @param ContainerBuilder $container Container der Anwendung
     *
     * @return string die Umgebung, z. B. prod oder test; prod, wenn sie nicht bekannt ist
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
