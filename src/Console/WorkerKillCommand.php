<?php
namespace Clicalmani\Queue\Console;

use Clicalmani\Console\Commands\Command;
use Clicalmani\Console\CrontabManager;
use Clicalmani\Console\CronWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Scheduler;

/**
 * Kill
 * * @package Clicalmani\Console
 * @author clicalmani
 */
#[AsCommand(
    name: 'queue:kill',
    description: 'Stop the consumer',
    hidden: false
)]
class WorkerKillCommand extends Command
{
    private string $tmpPath;

    public function __construct(protected $rootPath)
    {
        parent::__construct();
        $this->tmpPath = $rootPath . '/tmp';
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->mkdir($this->tmpPath . '/restart_worker.txt');
        
        $manager = new CrontabManager();

        $worker = new CronWorker(
            name: 'tonka_queue_jobs',
            script: __DIR__ . '/worker.php',
            lockFile: sys_get_temp_dir() . '/tonka_queue_jobs_worker.lock',
        );

        try {
            $uninstalled = $manager->uninstall($worker);
            $output->writeln($uninstalled
                ? '<info>Worker desinstallé.</info>'
                : '<info>Le worker est déjà desinstallé.</info>');
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}