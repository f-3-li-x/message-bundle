<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox;

use Psr\Log\LoggerInterface;
use ACAT\MessageBundle\Outbox\Entity\OutboxEntry;

/**
 * Outbox publisher: delivers the pending outbox entries to RabbitMQ in the order they were created and deletes every
 * entry right after its delivery is confirmed.
 */
final readonly class OutboxPublisher
{
    /**
     * Creates the publisher.
     *
     * @param OutboxEntryStore $entries access to the table outbox_entries
     * @param OutboxSender     $sender  handover of the messages to RabbitMQ
     * @param LoggerInterface  $logger  logger of the application
     */
    public function __construct(
        private OutboxEntryStore $entries,
        private OutboxSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs once: processes the entries ordered by created_at, then by id. If an entry cannot be delivered or has the
     * status failed, all later entries with the same aggregate_id are skipped; other aggregate_ids are not affected.
     * If RabbitMQ is unreachable, the run aborts and the attempt counters stay unchanged.
     *
     * @return PublishResult the result of the run
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
                    'Outbox run aborted: RabbitMQ is unreachable.',
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
     * Builds the log context for an entry; the payload is not part of it.
     *
     * @param OutboxEntry $entry affected entry
     *
     * @return array<string, mixed> id, event name, aggregate_id and attempt counter of the entry
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
     * Counts the failed attempt of an entry and saves it. If the entry thereby gets the status failed, this is logged
     * at level error.
     *
     * @param OutboxEntry              $entry     entry whose message was rejected or not confirmed
     * @param MessageRejectedException $exception rejection by the broker
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
                'Outbox entry set to failed after {attempts} rejected delivery attempts; it will not be delivered '
                . 'any more.',
                $context,
            );

            return;
        }

        $this->logger->warning('Outbox entry not delivered: delivery attempt {attempts} rejected.', $context);
    }
}
