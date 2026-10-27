<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Outbox\Command;

use Psr\Clock\ClockInterface;
use F3lix\MessageBundle\Outbox\OutboxPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Konsolenkommando <namensraum>:publish, standardmäßig outbox:publish: ein Lauf des Outbox-Publishers, z. B.
 * stündlich über einen Cron-Lauf. Den Namensraum legt message.outbox.command_namespace fest.
 */
final class PublishOutboxCommand extends Command
{
    public const string DEFAULT_NAME = 'outbox:publish';

    /**
     * Erzeugt das Kommando.
     *
     * @param OutboxPublisher $publisher Outbox-Publisher
     * @param ClockInterface  $clock     Uhr für den Zeitpunkt in der Ausgabe
     * @param string          $name      Name des Kommandos
     */
    public function __construct(
        private readonly OutboxPublisher $publisher,
        private readonly ClockInterface $clock,
        string $name = self::DEFAULT_NAME,
    ) {
        parent::__construct($name);
    }

    /**
     * Legt die Beschreibung fest.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Stellt die ausstehenden Outbox-Einträge an RabbitMQ zu.');
    }

    /**
     * Führt einen Lauf des Outbox-Publishers aus und gibt dessen Ergebnis aus.
     *
     * @param InputInterface  $input  Eingabe der Konsole
     * @param OutputInterface $output Ausgabe der Konsole
     *
     * @return int Command::SUCCESS nach einem vollständigen Lauf, Command::FAILURE, wenn der Lauf abgebrochen
     *             wurde, weil RabbitMQ nicht erreichbar ist
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->publisher->publish();

        $output->writeln(sprintf(
            '%s Outbox-Lauf: %d zugestellt, %d abgelehnt, %d übersprungen.',
            $this->clock->now()->format('c'),
            $result->delivered,
            $result->rejected,
            $result->skipped,
        ));

        if ($result->aborted) {
            $output->writeln('Lauf abgebrochen: RabbitMQ ist nicht erreichbar. Die Versuchszähler sind unverändert.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
