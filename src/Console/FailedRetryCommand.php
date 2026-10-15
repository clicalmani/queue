<?php

namespace Clicalmani\Queue\Console;

use Clicalmani\Console\Commands\Command;
use Clicalmani\Core\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'queue:failed:retry',
    description: 'Requeue a failed job for a new attempt'
)]
class FailedRetryCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'ID of the failed job to retry. If omitted, use --all.'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Retry all failed jobs (use --queue to filter by queue)'
            )
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_REQUIRED,
                'When used with --all, only retry jobs from this queue'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation when retrying multiple jobs'
            )
            ->addOption(
                'reset-attempts',
                null,
                InputOption::VALUE_NONE,
                'Reset the attempts counter to zero'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id     = $input->getArgument('id');
        $all    = $input->getOption('all');
        $queue  = $input->getOption('queue');
        $force  = $input->getOption('force');
        $reset  = $input->getOption('reset-attempts');

        // ── Cas 1 : retry d'un job précis ─────────────────────────────────────
        if ($id && ! $all) {
            return $this->retryOne($id, $reset, $output);
        }

        // ── Cas 2 : retry en masse ────────────────────────────────────────────
        if ($all) {
            return $this->retryAll($queue, $force, $reset, $input, $output);
        }

        // ── Cas 3 : ni --all ni id fourni ─────────────────────────────────────
        $output->writeln('<error>You must provide either a job ID or use --all.</error>');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  php tonka queue:failed:retry 42');
        $output->writeln('  php tonka queue:failed:retry --all --queue=notifications');

        return Command::FAILURE;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Retry d'un seul job
    // ────────────────────────────────────────────────────────────────────────

    private function retryOne(string $id, bool $reset, OutputInterface $output): int
    {
        $job = $this->findFailedJob($id);

        if (! $job) {
            $output->writeln("<error>No failed job found with ID {$id}.</error>");
            return Command::FAILURE;
        }

        $this->requeue([$job], $reset);

        $output->writeln("<info>Job #{$id} has been requeued successfully.</info>");
        $output->writeln("<comment>Queue    :</comment> {$job->queue}");
        $output->writeln("<comment>Error was:</comment> {$job->error}");

        return Command::SUCCESS;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Retry en masse
    // ────────────────────────────────────────────────────────────────────────

    private function retryAll(
        ?string $queue,
        bool $force,
        bool $reset,
        InputInterface $input,
        OutputInterface $output
    ): int {
        $query = DB::table('jobs')->whereNotNull('failed_at');

        if ($queue) {
            $query = $query->where('queue', $queue);
        }

        $jobs = $query->get();

        if (empty($jobs)) {
            $output->writeln('<info>No failed jobs to retry.</info>');
            return Command::SUCCESS;
        }

        $count = count($jobs);

        // Confirmation sauf en mode --force ou non-interactif
        if (! $force && $input->isInteractive()) {
            $output->writeln(sprintf(
                '<comment>About to requeue %d failed job(s)%s.</comment>',
                $count,
                $queue ? " from queue [{$queue}]" : ''
            ));

            if (! $this->confirm($input, $output, 'Continue?')) {
                $output->writeln('<comment>Aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $this->requeue($jobs, $reset);

        $output->writeln("<info>{$count} job(s) requeued successfully.</info>");

        // Résumé par queue
        $byQueue = [];
        foreach ($jobs as $job) {
            $byQueue[$job->queue] = ($byQueue[$job->queue] ?? 0) + 1;
        }

        $output->writeln('');
        $output->writeln('<comment>Breakdown by queue:</comment>');

        foreach ($byQueue as $name => $n) {
            $output->writeln("  - {$name}: {$n}");
        }

        return Command::SUCCESS;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Requeue effective
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Remet une collection de jobs en état "prêt à être traité".
     *
     * @param object[] $jobs
     */
    private function requeue(array $jobs, bool $reset): void
    {
        $ids = array_map(fn($job) => $job->id, $jobs);

        $update = [
            'failed_at'    => null,
            'reserved_at'  => null,
            'error'        => null,
            'error_class'  => null,
            'available_at' => date('Y-m-d H:i:s'),
        ];

        if ($reset) {
            $update['attempts'] = 0;
        }

        DB::table('jobs')
            ->whereIn('id', $ids)
            ->update($update);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function findFailedJob(string $id): ?object
    {
        return DB::table('jobs')
            ->where('id', $id)
            ->whereNotNull('failed_at')
            ->first();
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        if (method_exists($this, 'ask')) {
            $answer = $this->ask($input, $output, $question);
        } else {
            // Fallback basique
            $output->write("{$question} [y/N] ");
            $answer = trim(fgets(STDIN));
        }

        return in_array(strtolower($answer), ['y', 'yes', 'o', 'oui'], true);
    }
}