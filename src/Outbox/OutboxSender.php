<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use F3lix\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Übergibt die Nachricht eines Outbox-Eintrags an RabbitMQ und meldet, ob der Broker sie bestätigt hat.
 */
interface OutboxSender
{
    /**
     * Veröffentlicht die Nachricht eines Outbox-Eintrags an dessen Exchange und wartet auf die Bestätigung des
     * Brokers. Kehrt die Methode ohne Exception zurück, ist die Zustellung bestätigt.
     *
     * @param OutboxEntry $entry zuzustellender Eintrag
     *
     * @return void
     *
     * @throws MessageRejectedException   wenn der Broker die Nachricht ablehnt oder nicht bestätigt
     * @throws BrokerUnavailableException wenn RabbitMQ nicht erreichbar ist
     */
    public function send(OutboxEntry $entry): void;
}
