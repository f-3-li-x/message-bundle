<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

/**
 * Ordnet einem Event den Exchange zu: Die konfigurierten Änderungsereignisse gehen an den zentralen Exchange,
 * sofern einer konfiguriert ist, alle übrigen Events (z. B. Rückmeldungen zu Löschaufträgen) an den Exchange des
 * Service.
 */
final readonly class ExchangeResolver
{
    /** @var list<string> reguläre Ausdrücke der Event-Typen für den zentralen Exchange */
    private array $centralPatterns;

    /**
     * Erzeugt die Zuordnung.
     *
     * @param string       $service           Name des Service, zugleich Name seines Exchange
     * @param string|null  $centralExchange   zentraler Exchange oder null, wenn alle Events an den Service gehen
     * @param list<string> $centralEventTypes Event-Typen für den zentralen Exchange; * steht wie bei RabbitMQ für
     *                                        genau ein Wort, z. B. *.created
     */
    public function __construct(
        private string $service,
        private ?string $centralExchange,
        array $centralEventTypes,
    ) {
        $this->centralPatterns = array_map(self::toRegex(...), $centralEventTypes);
    }

    /**
     * Liefert den Exchange eines Events.
     *
     * @param string $eventType Event-Name, z. B. person.updated
     *
     * @return string der zentrale Exchange für die konfigurierten Event-Typen, sonst der Name des Service
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
     * Bildet aus einem Event-Typ mit Platzhaltern einen regulären Ausdruck.
     *
     * @param string $eventType Event-Typ, z. B. *.created
     *
     * @return string der reguläre Ausdruck, z. B. /^[^.]+\.created$/
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
