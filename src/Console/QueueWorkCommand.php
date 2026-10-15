<?php
namespace Clicalmani\Queue\Console;

use Clicalmani\Console\Commands\Command;
use Clicalmani\Console\CrontabManager;
use Clicalmani\Console\CronWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kill
 * * @package Clicalmani\Console
 * @author clicalmani
 */
#[AsCommand(
    name: 'queue:work',
    description: 'Start the queue worker',
    hidden: false
)]
class QueueWorkCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Define available options
     */
    protected function configure(): void
    {
        parent::configure();
        
        $this->addOption('queue', 'q', InputOption::VALUE_REQUIRED, 'Queues à consommer, dans l\'ordre de priorité', ['default']);
        $this->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Secondes d\'attente quand la queue est vide', 1);
        $this->addOption('memory', 'm', InputOption::VALUE_REQUIRED, 'Mémoire max (MB) avant arrêt gracieux', 128);
        $this->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Timeout par job en secondes', 60);
        $this->addOption('once', 'o', InputOption::VALUE_NONE, 'Traite un seul job puis sort');
        $this->addOption('stop-when-empty', 'swe', InputOption::VALUE_NONE, 'Sort quand la queue est vide');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $manager = new CrontabManager();

        $worker = new CronWorker(
            name: 'tonka_queue_jobs',
            script: __DIR__ . '/worker.php',
            lockFile: sys_get_temp_dir() . '/tonka_queue_jobs_worker.lock',
        );

        try {
            $installed = $manager->install($worker, $this->getOptions($input));
            $output->writeln($installed
                ? '<info>Worker installé.</info>'
                : '<info>Le worker est déjà configuré.</info>');
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function getOptions(InputInterface $input): array
    {
        $options = [];

        if ($queue = $input->getOption('queue')) $options['queue'] = $queue;
        if ($sleep = $input->getOption('sleep')) $options['sleep'] = $sleep;
        if ($memory = $input->getOption('memory')) $options['memory'] = $memory;
        if ($timeout = $input->getOption('timeout')) $options['timeout'] = $timeout;
        if ($once = $input->getOption('once')) $options['once'] = $once;
        if ($stopWhenEmpty = $input->getOption('stop-when-empty')) $options['stop-when-empty'] = $stopWhenEmpty;

        return $options;
    }
}