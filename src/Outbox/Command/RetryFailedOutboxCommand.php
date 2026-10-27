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
 * Console command <namespace>:retry-failed, outbox:retry-failed by default: sets outbox entries with the status
 * failed back to pending and the attempt counter to 0, optionally for a single entry. The namespace is set by
 * message.outbox.command_namespace.
 */
final class RetryFailedOutboxCommand extends Command
{
    public const string DEFAULT_NAME = 'outbox:retry-failed';

    private const string ARGUMENT_ID = 'id';

    /**
     * Creates the command.
     *
     * @param OutboxEntryStore $entries access to the table outbox_entries
     * @param string           $name    name of the command
     */
    public function __construct(
        private readonly OutboxEntryStore $entries,
        string $name = self::DEFAULT_NAME,
    ) {
        parent::__construct($name);
    }

    /**
     * Sets the description and the optional argument id.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Sets failed outbox entries back to pending and the attempt counter to 0.');
        $this->addArgument(
            self::ARGUMENT_ID,
            InputArgument::OPTIONAL,
            'UUID of a single outbox entry; without it, all failed entries are retried',
        );
    }

    /**
     * Retries the failed entries, with the argument id only this one.
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     *
     * @return int Command::SUCCESS if the entries were retried; Command::FAILURE if id is not a UUID or there is no
     *             failed entry with this id
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument(self::ARGUMENT_ID);
        $id = null;

        if ($argument !== null) {
            if (!is_string($argument) || !Uuid::isValid($argument)) {
                $output->writeln('The argument id is not a UUID.');

                return Command::FAILURE;
            }

            $id = Uuid::fromString($argument);
        }

        $count = $this->entries->retryFailed($id);

        if ($id !== null && $count === 0) {
            $output->writeln('There is no outbox entry with this id and the status failed.');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Outbox entries set to pending: %d', $count));

        return Command::SUCCESS;
    }
}
