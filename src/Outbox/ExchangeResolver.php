<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

/**
 * Assigns the exchange to an event: the configured change events go to the central exchange, if one is configured,
 * all other events (e.g. feedback on deletion requests) to the exchange of the service.
 */
final readonly class ExchangeResolver
{
    /** @var list<string> regular expressions of the event types for the central exchange */
    private array $centralPatterns;

    /**
     * Creates the resolver.
     *
     * @param string       $service           name of the service, also the name of its exchange
     * @param string|null  $centralExchange   central exchange, or null if all events go to the service
     * @param list<string> $centralEventTypes event types for the central exchange; as in RabbitMQ, * stands for
     *                                        exactly one word, e.g. *.created
     */
    public function __construct(
        private string $service,
        private ?string $centralExchange,
        array $centralEventTypes,
    ) {
        $this->centralPatterns = array_map(self::toRegex(...), $centralEventTypes);
    }

    /**
     * Returns the exchange of an event.
     *
     * @param string $eventType event name, e.g. person.updated
     *
     * @return string the central exchange for the configured event types, otherwise the name of the service
     */
    public function exchangeFor(string $eventType): string
    {
        if ($this->centralExchange === null) {
            return $this->service;
        }

        foreach ($this->centralPatterns as $pattern) {
            if (preg_match($pattern, $eventType) === 1) {
                return $this->centralExchange;
            }
        }

        return $this->service;
    }

    /**
     * Turns an event type with wildcards into a regular expression.
     *
     * @param string $eventType event type, e.g. *.created
     *
     * @return string the regular expression, e.g. /^[^.]+\.created$/
     */
    private static function toRegex(string $eventType): string
    {
        $words = array_map(
            static fn (string $word): string => $word === '*' ? '[^.]+' : preg_quote($word, '/'),
            explode('.', $eventType),
        );

        return '/^' . implode('\.', $words) . '$/';
    }
}
