<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Consumer;

use Throwable;
use Psr\Log\LoggerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Wiederholt die Verarbeitung einer konsumierten Nachricht bei einem technischen Fehler innerhalb desselben
 * Prozesses: höchstens RABBITMQ_RETRY Versuche mit den festen Wartezeiten 1, 2, 4, 8, 8, 8, 8, 8 und 8
 * Sekunden. Die Nachricht bleibt währenddessen unbestätigt; Symfony Messenger selbst wiederholt nicht
 * (max_retries: 0).
 */
final readonly class RetryingExecutor
{
    /** @var list<int> Wartezeit in Sekunden nach dem ersten, zweiten, ... fehlgeschlagenen Versuch */
    public const array WAIT_SECONDS = [1, 2, 4, 8, 8, 8, 8, 8, 8];

    private int $maxAttempts;

    /**
     * Erzeugt die Wiederholung. Die Zahl der Versuche liegt zwischen 1 und 10, da es neun feste Wartezeiten gibt.
     *
     * @param int             $maxAttempts     Wert von RABBITMQ_RETRY: höchste Zahl der Versuche je Nachricht
     * @param ManagerRegistry $managerRegistry Zugang zum EntityManager, der vor jedem erneuten Versuch
     *                                         zurückgesetzt wird
     * @param ClockInterface  $clock           Uhr, über die gewartet wird; in Tests ersetzbar
     * @param LoggerInterface $logger          Protokoll der fehlgeschlagenen Versuche
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
     * Liefert die höchste Zahl der Versuche je Nachricht.
     *
     * @return int zwischen 1 und 10
     */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Führt die Verarbeitung aus und wiederholt sie nach jedem Fehler, bis sie gelingt oder die Versuche
     * aufgebraucht sind. Vor jedem erneuten Versuch wird gewartet und danach der EntityManager zurückgesetzt, da
     * Doctrine ihn nach einem Fehler in einer Transaktion schließt.
     *
     * @param callable(): mixed     $operation Verarbeitung der Nachricht
     * @param array<string, string> $context   unkritische Angaben zur Nachricht für das Protokoll, z. B. ticketId
     *
     * @return void
     *
     * @throws UnrecoverableMessageHandlingException wenn auch der letzte Versuch fehlschlägt; Symfony Messenger
     *                                               lehnt die Nachricht daraufhin ab (reject ohne Requeue)
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
     * Protokolliert den fehlgeschlagenen Versuch, wartet die feste Wartezeit ab und setzt den EntityManager zurück.
     *
     * @param int                   $attempt Nummer des fehlgeschlagenen Versuchs, beginnend bei 1
     * @param Throwable             $failure aufgetretener Fehler
     * @param array<string, string> $context unkritische Angaben zur Nachricht für das Protokoll
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
