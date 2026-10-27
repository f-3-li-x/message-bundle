<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\DependencyInjection;

use ACAT\MessageBundle\Deletion\DeletionRequestSerializer;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Konfiguration des Bundles unter dem Schlüssel message. Konfigurierbar ist nur, was sich je Service
 * unterscheidet; Versuche, Wartezeiten, AMQP-Properties und Tabellenschema sind fest.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ROOT = 'message';

    /** @var list<string> Event-Typen, die an den zentralen Exchange gehen, sofern einer konfiguriert ist */
    public const array DEFAULT_CENTRAL_EVENT_TYPES = ['*.created', '*.updated'];

    public const string DEFAULT_COMMAND_NAMESPACE = 'outbox';

    /** Namensraum der Konsolenkommandos: Wörter aus Kleinbuchstaben, Ziffern, - und _, getrennt durch : */
    private const string COMMAND_NAMESPACE_PATTERN = '/^[a-z0-9_-]+(:[a-z0-9_-]+)*$/';

    /**
     * Liefert den Aufbau der Konfiguration.
     *
     * @return TreeBuilder der Aufbau unter dem Schlüssel message
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT);

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('service')
                    ->info('Name des Service: eigener Exchange, app_id, Routing-Key der Log-Events')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('outbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('central_exchange')
                            ->info('Zentraler Exchange für die Änderungsereignisse; null: alle Events an den Service')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('central_event_types')
                            ->info('Event-Typen für den zentralen Exchange; * steht für genau ein Wort')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_CENTRAL_EVENT_TYPES)
                        ->end()
                        ->scalarNode('command_namespace')
                            ->info('Namensraum der Kommandos, z. B. outbox für outbox:publish und outbox:retry-failed')
                            ->defaultValue(self::DEFAULT_COMMAND_NAMESPACE)
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value)
                                    || preg_match(self::COMMAND_NAMESPACE_PATTERN, $value) !== 1)
                                ->thenInvalid('Der Namensraum %s der Kommandos ist ungültig.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('consumers')
                    ->info('Je Eintrag ein Messenger-Transport, der Name ist der Name des Transports')
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
                            ->info('AmqpLogHandler; in der Umgebung test immer abgeschaltet')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('redact_fields')
                            ->info('Felder, deren Werte nicht ins Log gelangen; leer bedeutet ohne Redaction-Processor')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
