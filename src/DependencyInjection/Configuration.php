<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\DependencyInjection;

use ACAT\MessageBundle\Deletion\DeletionRequestSerializer;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration of the bundle under the key message. Only what differs per service is configurable; attempts, waiting
 * times, AMQP properties and table schema are fixed.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ROOT = 'message';

    /** @var list<string> event types that go to the central exchange, if one is configured */
    public const array DEFAULT_CENTRAL_EVENT_TYPES = ['*.created', '*.updated'];

    public const string DEFAULT_COMMAND_NAMESPACE = 'outbox';

    /** Namespace of the console commands: words of lower-case letters, digits, - and _, separated by : */
    private const string COMMAND_NAMESPACE_PATTERN = '/^[a-z0-9_-]+(:[a-z0-9_-]+)*$/';

    /**
     * Returns the structure of the configuration.
     *
     * @return TreeBuilder the structure under the key message
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT);

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('service')
                    ->info('Name of the service: own exchange, app_id, routing key of the log events')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('outbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('central_exchange')
                            ->info('Central exchange for change events; null: all events go to the service\'s exchange')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('central_event_types')
                            ->info('Event types for the central exchange; * stands for exactly one word')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_CENTRAL_EVENT_TYPES)
                        ->end()
                        ->scalarNode('command_namespace')
                            ->info('Namespace of the commands, e.g. outbox for outbox:publish and outbox:retry-failed')
                            ->defaultValue(self::DEFAULT_COMMAND_NAMESPACE)
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value)
                                    || preg_match(self::COMMAND_NAMESPACE_PATTERN, $value) !== 1)
                                ->thenInvalid('The command namespace %s is invalid.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('consumers')
                    ->info('One Messenger transport per entry; the key is the name of the transport')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('queue')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('serializer')->defaultValue(DeletionRequestSerializer::class)->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('AmqpLogHandler; always disabled in the test environment')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('redact_fields')
                            ->info('Fields whose values must not reach the log; empty means no redaction processor')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
