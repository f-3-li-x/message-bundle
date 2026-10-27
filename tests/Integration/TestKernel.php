<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Integration;

use F3lix\MessageBundle\MessageBundle;
use Symfony\Component\HttpKernel\Kernel;
use F3lix\MessageBundle\Outbox\OutboxWriter;
use F3lix\MessageBundle\Message\BodySerializer;
use F3lix\MessageBundle\Outbox\OutboxEntryStore;
use F3lix\MessageBundle\Consumer\RetryingExecutor;
use F3lix\MessageBundle\Deletion\DeletionRequestSerializer;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Minimale Symfony-Anwendung mit dem Bundle, wie ein Service es einbindet: Doctrine auf SQLite im Speicher,
 * Monolog mit dem AmqpLogHandler, ein Consumer für Löschaufträge.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string SERVICE = 'test-service';

    public const string CENTRAL_EXCHANGE = 'domain-events';

    /** @var list<class-string> Services, die ein Service aus Infrastructure und Api heraus nutzt */
    private const array SERVICES_USED_BY_A_SERVICE = [
        OutboxWriter::class,
        OutboxEntryStore::class,
        RetryingExecutor::class,
        BodySerializer::class,
        DeletionRequestSerializer::class,
    ];

    /**
     * Liefert die Bundles der Anwendung.
     *
     * @return iterable<int, \Symfony\Component\HttpKernel\Bundle\BundleInterface> die Bundles
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new MonologBundle();
        yield new MessageBundle();
    }

    /**
     * Liefert das Verzeichnis des Projekts.
     *
     * @return string das Wurzelverzeichnis des Bundles
     */
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Liefert das Cache-Verzeichnis.
     *
     * @return string Verzeichnis unter var/
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    /**
     * Liefert das Log-Verzeichnis.
     *
     * @return string Verzeichnis unter var/
     */
    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    /**
     * Konfiguriert die Anwendung.
     *
     * @param ContainerConfigurator $container Konfiguration des Containers
     *
     * @return void
     */
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test'   => true,
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [],
        ]);

        $container->extension('monolog', [
            'handlers' => [
                'amqp' => ['type' => 'service', 'id' => 'message.logging.amqp_handler', 'channels' => ['!doctrine']],
            ],
        ]);

        $container->extension('message', [
            'service'   => self::SERVICE,
            'outbox'    => ['central_exchange' => self::CENTRAL_EXCHANGE],
            'consumers' => ['deletion_requests' => ['queue' => '%env(RABBITMQ_DELETION_QUEUE)%']],
            'logging'   => ['redact_fields' => ['firstName']],
        ]);

        // Ein Service nutzt diese Services über Dependency Injection; die Aliase halten sie für die Tests im Container.
        $services = $container->services();

        foreach (self::SERVICES_USED_BY_A_SERVICE as $id) {
            $services->alias('test.' . $id, $id)->public();
        }
    }
}
