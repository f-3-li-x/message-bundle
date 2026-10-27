<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox;

use Psr\Log\LoggerInterface;
use F3lix\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Outbox-Publisher: stellt die ausstehenden Outbox-Einträge in der Reihenfolge ihres Entstehens an RabbitMQ
 * zu und löscht jeden Eintrag unmittelbar nach bestätigter Zustellung.
 */
final readonly class OutboxPublisher
{
    /**
     * Erzeugt den Publisher.
     *
     * @param OutboxEntryStore $entries Zugriff auf die Tabelle outbox_entries
     * @param OutboxSender     $sender  Übergabe der Nachrichten an RabbitMQ
     * @param LoggerInterface  $logger  Logger der Anwendung
     */
    public function __construct(
        private OutboxEntryStore $entries,
        private OutboxSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Führt einen Lauf aus: verarbeitet die Einträge sortiert nach created_at, bei Gleichstand nach id. Kann ein
     * Eintrag nicht zugestellt werden oder steht er auf failed, werden alle späteren Einträge mit derselben
     * aggregate_id übersprungen; andere aggregate_ids sind davon nicht betroffen. Ist RabbitMQ nicht erreichbar,
     * bricht der Lauf ab und die Versuchszähler bleiben unverändert.
     *
     * @return PublishResult das Ergebnis des Laufs
     */
    public function publish(): PublishResult
    {
        $blockedAggregateIds = [];
        $delivered = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($this->entries->findAllOrdered() as $entry) {
            $aggregateId = $entry->getAggregateId();

            if (isset($blockedAggregateIds[$aggregateId])) {
                ++$skipped;

                continue;
            }

            if ($entry->isFailed()) {
                $blockedAggregateIds[$aggregateId] = true;

                continue;
            }

            try {
                $this->sender->send($entry);
            } catch (BrokerUnavailableException $exception) {
                $this->logger->error(
                    'Outbox-Lauf abgebrochen: RabbitMQ ist nicht erreichbar.',
                    self::context($entry) + ['exception' => $exception],
                );

                return new PublishResult($delivered, $rejected, $skipped, true);
            } catch (MessageRejectedException $exception) {
                $this->countFailedAttempt($entry, $exception);
                $blockedAggregateIds[$aggregateId] = true;
                ++$rejected;

                continue;
            }

            $this->entries->remove($entry);
            ++$delivered;
        }

        return new PublishResult($delivered, $rejected, $skipped, false);
    }

    /**
     * Bildet den Log-Context zu einem Eintrag; das Payload gehört nicht dazu.
     *
     * @param OutboxEntry $entry betroffener Eintrag
     *
     * @return array<string, mixed> id, Event-Name, aggregate_id und Versuchszähler des Eintrags
     */
    private static function context(OutboxEntry $entry): array
    {
        return [
            'id'          => $entry->getId()->toRfc4122(),
            'eventType'   => $entry->getEventType(),
            'aggregateId' => $entry->getAggregateId(),
            'attempts'    => $entry->getAttempts(),
        ];
    }

    /**
     * Zählt den Fehlversuch eines Eintrags und speichert ihn. Erreicht der Eintrag damit den Status failed, wird das
     * mit Level error geloggt.
     *
     * @param OutboxEntry              $entry     Eintrag, dessen Nachricht abgelehnt oder nicht bestätigt wurde
     * @param MessageRejectedException $exception Ablehnung durch den Broker
     *
     * @return void
     */
    private function countFailedAttempt(OutboxEntry $entry, MessageRejectedException $exception): void
    {
        $entry->recordFailedAttempt();
        $this->entries->save($entry);

        $context = self::context($entry) + ['exception' => $exception];

        if ($entry->isFailed()) {
            $this->logger->error(
                'Outbox-Eintrag nach {attempts} abgelehnten Zustellversuchen auf failed gesetzt; er wird nicht '
                . 'weiter zugestellt.',
                $context,
            );

            return;
        }

        $this->logger->warning('Outbox-Eintrag nicht zugestellt: Zustellversuch {attempts} abgelehnt.', $context);
    }
}
