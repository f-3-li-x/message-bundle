<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use ACAT\MessageBundle\Outbox\ExchangeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ACAT\MessageBundle\DependencyInjection\Configuration;

final class ExchangeResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function eventTypesAndExchanges(): iterable
    {
        yield 'created an domain-events' => ['person.created', 'domain-events'];
        yield 'updated an domain-events' => ['person.updated', 'domain-events'];
        yield 'deleted an den Service' => ['person.deleted', 'person-service'];
        yield 'deletion.failed an den Service' => ['person.deletion.failed', 'person-service'];
        yield 'deletion.processed an den Service' => ['organisation.deletion.processed', 'person-service'];
        yield '* deckt genau ein Wort' => ['person.contact.created', 'person-service'];
    }

    #[DataProvider('eventTypesAndExchanges')]
    public function testAssignsTheExchangeByEventType(string $eventType, string $exchange): void
    {
        $resolver = new ExchangeResolver('person-service', 'domain-events', Configuration::DEFAULT_CENTRAL_EVENT_TYPES);

        self::assertSame($exchange, $resolver->exchangeFor($eventType));
    }

    public function testTakesAdditionalCentralEventTypes(): void
    {
        $resolver = new ExchangeResolver('program-service', 'domain-events', ['*.created', '*.updated', 'program.deleted']);

        self::assertSame('domain-events', $resolver->exchangeFor('program.deleted'));
        self::assertSame('program-service', $resolver->exchangeFor('person.deleted'));
    }

    public function testWithoutCentralExchangeEveryEventGoesToTheService(): void
    {
        $resolver = new ExchangeResolver('person-service', null, Configuration::DEFAULT_CENTRAL_EVENT_TYPES);

        self::assertSame('person-service', $resolver->exchangeFor('person.created'));
        self::assertSame('person-service', $resolver->exchangeFor('person.deleted'));
    }
}
