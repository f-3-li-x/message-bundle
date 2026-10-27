<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox\Command;

use Symfony\Component\Uid\Uuid;
use ACAT\MessageBundle\Outbox\OutboxEntryStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Konsolenkommando <namensraum>:retry-failed, standardmäßig outbox:retry-failed: setzt Outbox-Einträge mit Status
 * failed auf pending und den Versuchszähler auf 0, wahlweise für einen einzelnen Eintrag. Den Namensraum legt
 * message.outbox.command_namespace fest.
 */
final class RetryFailedOutboxCommand extends Command
{
    public const string DEFAULT_NAME = 'outbox:retry-failed';

    private const string ARGUMENT_ID = 'id';

    /**
     * Erzeugt das Kommando.
     *
     * @param OutboxEntryStore $entries Zugriff auf die Tabelle outbox_entries
     * @param string           $name    Name des Kommandos
     */
    public function __construct(
        private readonly OutboxEntryStore $entries,
        string $name = self::DEFAULT_NAME,
    ) {
        parent::__construct($name);
    }

    /**
     * Legt Beschreibung und das optionale Argument id fest.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Setzt fehlgeschlagene Outbox-Einträge auf pending und den Versuchszähler auf 0.');
        $this->addArgument(
            self::ARGUMENT_ID,
            InputArgument::OPTIONAL,
            'UUID eines einzelnen Outbox-Eintrags; ohne Angabe werden alle fehlgeschlagenen Einträge angestoßen',
        );
    }

    /**
     * Stößt die fehlgeschlagenen Einträge wieder an, mit dem Argument id nur diesen einen.
     *
     * @param InputInterface  $input  Eingabe der Konsole
     * @param OutputInterface $output Ausgabe der Konsole
     *
     * @return int Command::SUCCESS, wenn die Einträge angestoßen wurden; Command::FAILURE, wenn id keine UUID ist
     *             oder es keinen fehlgeschlagenen Eintrag mit dieser id gibt
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument(self::ARGUMENT_ID);
        $id = null;

        if ($argument !== null) {
            if (!is_string($argument) || !Uuid::isValid($argument)) {
                $output->writeln('Das Argument id ist keine UUID.');

                return Command::FAILURE;
            }

            $id = Uuid::fromString($argument);
        }

        $count = $this->entries->retryFailed($id);

        if ($id !== null && $count === 0) {
            $output->writeln('Es gibt keinen Outbox-Eintrag mit dieser id und dem Status failed.');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Auf pending gesetzte Outbox-Einträge: %d', $count));

        return Command::SUCCESS;
    }
}
