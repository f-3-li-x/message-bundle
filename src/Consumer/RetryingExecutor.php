<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Consumer;

use Throwable;
use Psr\Log\LoggerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Retries the processing of a consumed message after a technical failure within the same process: at most
 * RABBITMQ_RETRY attempts with the fixed waiting times 1, 2, 4, 8, 8, 8, 8, 8 and 8 seconds. The message stays
 * unacknowledged in the meantime; Symfony Messenger itself does not retry (max_retries: 0).
 */
final readonly class RetryingExecutor
{
    /** @var list<int> waiting time in seconds after the first, second, ... failed attempt */
    public const array WAIT_SECONDS = [1, 2, 4, 8, 8, 8, 8, 8, 8];

    private int $maxAttempts;

    /**
     * Creates the executor. The number of attempts lies between 1 and 10, as there are nine fixed waiting times.
     *
     * @param int             $maxAttempts     value of RABBITMQ_RETRY: maximum number of attempts per message
     * @param ManagerRegistry $managerRegistry access to the EntityManager, which is reset before every further attempt
     * @param ClockInterface  $clock           clock used for waiting; replaceable in tests
     * @param LoggerInterface $logger          log of the failed attempts
     */
    public function __construct(
        int $maxAttempts,
        private ManagerRegistry $managerRegistry,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
        $this->maxAttempts = max(1, min($maxAttempts, count(self::WAIT_SECONDS) + 1));
    }

    /**
     * Returns the maximum number of attempts per message.
     *
     * @return int between 1 and 10
     */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Runs the processing and repeats it after every failure until it succeeds or the attempts are used up. Before
     * every further attempt, it waits and then resets the EntityManager, since Doctrine closes it after a failure in a
     * transaction.
     *
     * @param callable(): mixed     $operation processing of the message
     * @param array<string, string> $context   non-sensitive details of the message for the log, e.g. ticketId
     *
     * @return void
     *
     * @throws UnrecoverableMessageHandlingException if the last attempt fails as well; Symfony Messenger then
     *                                               rejects the message (reject without requeue)
     */
    public function run(callable $operation, array $context = []): void
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                $operation();

                return;
            } catch (Throwable $failure) {
                if ($attempt >= $this->maxAttempts) {
                    throw new UnrecoverableMessageHandlingException(
                        sprintf('Die Verarbeitung ist nach %d Versuchen endgültig fehlgeschlagen.', $attempt),
                        0,
                        $failure,
                    );
                }

                $this->waitAndReset($attempt, $failure, $context);
            }
        }
    }

    /**
     * Logs the failed attempt, waits the fixed waiting time and resets the EntityManager.
     *
     * @param int                   $attempt number of the failed attempt, starting at 1
     * @param Throwable             $failure the failure that occurred
     * @param array<string, string> $context non-sensitive details of the message for the log
     *
     * @return void
     */
    private function waitAndReset(int $attempt, Throwable $failure, array $context): void
    {
        $waitSeconds = self::WAIT_SECONDS[$attempt - 1];

        $this->logger->warning(
            'Verarbeitung einer konsumierten Nachricht fehlgeschlagen (Versuch {attempt} von {maxAttempts});'
            . ' erneuter Versuch in {waitSeconds} s.',
            $context + [
                'attempt'     => $attempt,
                'maxAttempts' => $this->maxAttempts,
                'waitSeconds' => $waitSeconds,
                'exception'   => $failure,
            ],
        );

        $this->clock->sleep($waitSeconds);
        $this->managerRegistry->resetManager();
    }
}
