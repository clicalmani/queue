<?php

namespace Clicalmani\Queue\Console;

use Clicalmani\Console\Commands\Command;
use Clicalmani\Core\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

#[AsCommand(
    name: 'queue:failed:show',
    description: 'List or inspect failed jobs'
)]
class FailedShowCommand extends Command
{
    private string $table;

    public function __construct(protected $rootPath = null)
    {
        parent::__construct($rootPath);

        $this->table = config('queue.connections.database.table', 'jobs') ?? '';
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'ID of a specific failed job')
            ->addOption(
                'remove',
                'r',
                InputOption::VALUE_NONE,
                'Permanently remove the specified job without retrying it'
            )
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by queue name'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of jobs to display',
                '50'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        // ── CAS 1 : Suppression ciblée (--remove) ─────────────────────────────
        if ($id && $input->getOption('remove')) {
            return $this->removeJob($id, $output);
        }

        // ── CAS 2 : Inspection d'un job précis ────────────────────────────────
        if ($id) {
            return $this->showJob($id, $input, $output);
        }

        // ── CAS 3 : Liste des jobs échoués ────────────────────────────────────
        return $this->listJobs($input, $output);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Suppression
    // ────────────────────────────────────────────────────────────────────────

    private function removeJob(string $id, OutputInterface $output): int
    {
        $job = $this->findFailedJob($id);

        if (! $job) {
            $output->writeln("<error>No failed job found with ID {$id}.</error>");
            return Command::FAILURE;
        }

        DB::table($this->table)->where('id', $id)->delete();

        $output->writeln("<info>Job #{$id} has been permanently removed from quarantine.</info>");

        return Command::SUCCESS;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Inspection d'un job
    // ────────────────────────────────────────────────────────────────────────

    private function showJob(string $id, InputInterface $input, OutputInterface $output): int
    {
        $job = $this->findFailedJob($id);

        if (! $job) {
            $output->writeln("<error>No failed job found with ID {$id}.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>=== Failed Job #{$job->id} ===</info>");
        $output->writeln("<comment>Queue       :</comment> {$job->queue}");
        $output->writeln("<comment>Failed At   :</comment> {$job->failed_at}");
        $output->writeln("<comment>Attempts    :</comment> {$job->attempts}");

        if ($job->error_class) {
            $output->writeln("<comment>Exception   :</comment> <error>{$job->error_class}</error>");
        }

        $output->writeln("<comment>Error       :</comment> <error>{$job->error}</error>");

        // Détails en mode verbose
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->showPayloadDetails($job, $output);
        } else {
            $output->writeln('');
            $output->writeln(
                "<comment>(Add '--verbose' or '-v' to inspect the raw payload)</comment>"
            );
        }

        return Command::SUCCESS;
    }

    private function showPayloadDetails(object $job, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<comment>--- Raw payload (serialized) ---</comment>');
        $output->writeln(substr($job->payload, 0, 500) . (strlen($job->payload) > 500 ? '…' : ''));

        $output->writeln('');
        $output->writeln('<comment>--- Payload inspection ---</comment>');

        try {
            $unserialized = @unserialize($job->payload);

            if ($unserialized === false) {
                $output->writeln('<error>Unable to unserialize payload. Binary or corrupted data.</error>');
                return;
            }

            $this->renderPayloadTree($unserialized, $output);
        } catch (\Throwable $e) {
            $output->writeln("<error>Payload inspection failed: {$e->getMessage()}</error>");
        }
    }

    /**
     * Affiche un résumé lisible du NotificationJob désérialisé.
     */
    private function renderPayloadTree(object $job, OutputInterface $output): void
    {
        if (! $job instanceof \Clicalmani\Core\Queue\NotificationJob) {
            $output->writeln('<error>Unknown payload type: ' . $job::class . '</error>');
            return;
        }

        $output->writeln("Type          : <info>" . $job::class . "</info>");
        $output->writeln("Notification  : <info>" . $job->notification()::class . "</info>");
        $output->writeln("Notifiable    : <info>" . $job->notifiable()::class . "</info>");
        $output->writeln("Queue         : {$job->queue()}");
        $output->writeln("Tries         : {$job->tries()}");

        $backoff = $job->backoff();
        $output->writeln("Backoff       : " . (is_array($backoff) ? implode(', ', $backoff) : $backoff));
        $output->writeln("Timeout       : {$job->timeout()}s");

        $output->writeln('');
        $output->writeln('<comment>--- Notification properties ---</comment>');

        $reflection = new \ReflectionClass($job->notification());

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);

            try {
                $value = $property->getValue($job->notification());
                $output->writeln(sprintf(
                    '  <info>%s</info> = %s',
                    $property->getName(),
                    $this->formatValue($value)
                ));
            } catch (\Throwable $e) {
                $output->writeln("  <info>{$property->getName()}</info> = <error>unreadable</error>");
            }
        }

        // Canaux prévus
        if (method_exists($job->notification(), 'via')) {
            $channels = $job->notification()->via($job->notifiable());

            $output->writeln('');
            $output->writeln('<comment>--- Channels ---</comment>');
            $output->writeln('  ' . implode(', ', array_map(
                fn($c) => $c instanceof \BackedEnum ? $c->value : (string) $c,
                $channels
            )));
        }
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            is_scalar($value)  => (string) $value,
            is_array($value)   => '[' . count($value) . ' items]',
            $value === null    => 'null',
            is_object($value)  => $value::class,
            default            => gettype($value),
        };
    }

    // ────────────────────────────────────────────────────────────────────────
    // Liste
    // ────────────────────────────────────────────────────────────────────────

    private function listJobs(InputInterface $input, OutputInterface $output): int
    {
        $query = DB::table($this->table)->whereNotNull('failed_at');

        if ($queue = $input->getOption('queue')) {
            $query = $query->where('queue', $queue);
        }

        $limit = (int) $input->getOption('limit');

        $jobs = $query
            ->orderBy('failed_at', 'DESC')
            ->limit($limit)
            ->get();

        if (empty($jobs)) {
            $output->writeln('<info>No failed jobs in quarantine.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'Queue',
            'Notification',
            'Exception',
            'Error (Excerpt)',
            'Failed At',
        ]);

        foreach ($jobs as $job) {
            $table->addRow([
                $job->id,
                $job->queue,
                $this->extractNotificationName($job->payload),
                $job->error_class ?? 'Unknown',
                $this->excerpt($job->error, 50),
                $job->failed_at,
            ]);
        }

        $table->render();

        $count = count($jobs);
        $output->writeln('');
        $output->writeln("<info>{$count} failed job(s) displayed.</info>");

        return Command::SUCCESS;
    }

    /**
     * Tente d'extraire le nom de la notification depuis le payload sérialisé.
     */
    private function extractNotificationName(string $payload): string
    {
        try {
            $job = @unserialize($payload);

            if ($job instanceof \Clicalmani\Core\Queue\NotificationJob) {
                return basename(str_replace('\\', '/', $job->notification()::class));
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return 'Unknown';
    }

    private function excerpt(?string $text, int $length = 50): string
    {
        if ($text === null) {
            return '';
        }

        return strlen($text) > $length
            ? substr($text, 0, $length - 3) . '...'
            : $text;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helper
    // ────────────────────────────────────────────────────────────────────────

    private function findFailedJob(string $id): ?object
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->whereNotNull('failed_at')
            ->first();
    }
}