<?php
namespace Clicalmani\Queue\Console;

use Clicalmani\Console\Commands\Command;
use Clicalmani\Core\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Scheduler;

/**
 * MigrateCommand class
 * * @package Clicalmani\Queue
 * @author clicalmani
 */
#[AsCommand(
    name: 'queue:migrate',
    description: 'Migrate jobs table',
    hidden: false
)]
class MigrateCommand extends Command
{
    public function __construct(protected $rootPath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $connections = config('database.connections');
        $default_connection = config('database.default');
        $queue_table = config('queue.connections.database.table');

        if ($connections && $default_connection && $queue_table) {
            $collation = $connections[$default_connection]['collation'];
            $charset   = $connections[$default_connection]['charset'];

            try {
                $queue_table = env('DB_TABLE_PREFIX', '') . $queue_table;
                $queueSQL = <<<SQL
                CREATE TABLE IF NOT EXISTS $queue_table (
                    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `queue`         VARCHAR(255) NOT NULL,
                    `payload`       LONGTEXT NOT NULL,
                    `attempts`      INT DEFAULT 0,
                    `available_at`  DATETIME NOT NULL,
                    `reserved_at`   DATETIME NULL,
                    `failed_at`     DATETIME NULL,
                    `error`         TEXT NULL,
                    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_queue_available (`queue`, `available_at`, `reserved_at`)
                ) DEFAULT COLLATE = $collation DEFAULT CHARACTER SET = $charset;
                SQL;
                DB::getPdo()->exec($queueSQL);

                $output->writeln("<info>Migration executed successfully.</info>");

                return Command::SUCCESS;
            } catch (\PDOException $e) {
                $output->writeln('<error>Unable to migrate ' . $queue_table . ' tables.</error>');
                $output->writeln($e->getMessage());
            }
        }

        return Command::FAILURE;
    }
}