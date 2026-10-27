<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Integration;

use ACAT\MessageBundle\MessageBundle;
use Symfony\Component\HttpKernel\Kernel;
use ACAT\MessageBundle\Outbox\OutboxWriter;
use ACAT\MessageBundle\Message\BodySerializer;
use ACAT\MessageBundle\Outbox\OutboxEntryStore;
use ACAT\MessageBundle\Consumer\RetryingExecutor;
use ACAT\MessageBundle\Deletion\DeletionRequestSerializer;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Minimal Symfony application with the bundle, as a service includes it: Doctrine on in-memory SQLite, Monolog
 * with the AmqpLogHandler, a consumer for deletion requests.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string SERVICE = 'test-service';

    public const string CENTRAL_EXCHANGE = 'domain-events';

    /** @var list<class-string> services that a service uses from its infrastructure and interface layers */
    private const array SERVICES_USED_BY_A_SERVICE = [
        OutboxWriter::class,
        OutboxEntryStore::class,
        RetryingExecutor::class,
        BodySerializer::class,
        DeletionRequestSerializer::class,
    ];

    /**
     * Returns the bundles of the application.
     *
     * @return iterable<int, \Symfony\Component\HttpKernel\Bundle\BundleInterface> the bundles
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new MonologBundle();
        yield new MessageBundle();
    }

    /**
     * Returns the project directory.
     *
     * @return string the root directory of the bundle
     */
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Returns the cache directory.
     *
     * @return string directory under var/
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    /**
     * Returns the log directory.
     *
     * @return string directory under var/
     */
    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    /**
     * Configures the application.
     *
     * @param ContainerConfigurator $container configuration of the container
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

        // A service uses these services via dependency injection; the aliases keep them in the container for tests.
        $services = $container->services();

        foreach (self::SERVICES_USED_BY_A_SERVICE as $id) {
            $services->alias('test.' . $id, $id)->public();
        }
    }
}
