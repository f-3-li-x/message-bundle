<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Outbox\Command;

use Psr\Clock\ClockInterface;
use ACAT\MessageBundle\Outbox\OutboxPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command <namespace>:publish, outbox:publish by default: one run of the outbox publisher, e.g. hourly via
 * cron. The namespace is set by message.outbox.command_namespace.
 */
final class PublishOutboxCommand extends Command
{
    public const string DEFAULT_NAME = 'outbox:publish';

    /**
     * Creates the command.
     *
     * @param OutboxPublisher $publisher outbox publisher
     * @param ClockInterface  $clock     clock for the point in time in the output
     * @param string          $name      name of the command
     */
    public function __construct(
        private readonly OutboxPublisher $publisher,
        private readonly ClockInterface $clock,
        string $name = self::DEFAULT_NAME,
    ) {
        parent::__construct($name);
    }

    /**
     * Sets the description.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Delivers the pending outbox entries to RabbitMQ.');
    }

    /**
     * Runs the outbox publisher once and prints its result.
     *
     * @param InputInterface  $input  console input
     * @param OutputInterface $output console output
     *
     * @return int Command::SUCCESS after a complete run, Command::FAILURE if the run was aborted because RabbitMQ is
     *             unreachable
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->publisher->publish();

        $output->writeln(sprintf(
            '%s Outbox run: %d delivered, %d rejected, %d skipped.',
            $this->clock->now()->format('c'),
            $result->delivered,
            $result->rejected,
            $result->skipped,
        ));

        if ($result->aborted) {
            $output->writeln('Run aborted: RabbitMQ is unreachable. The attempt counters are unchanged.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
