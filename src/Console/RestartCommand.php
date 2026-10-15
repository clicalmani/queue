<?php
namespace Clicalmani\Queue\Console;

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
    name: 'queue:restart',
    description: 'Restart the queue worker',
    hidden: false
)]
class RestartCommand extends QueueWorkCommand
{
    public function __construct(protected $rootPath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $kill = new ArrayInput([
            'command' => 'queue:kill'
        ]);

        if (0 !== $this->getApplication()->doRun($kill, $output)) {
            return Command::FAILURE;
        }

        parent::execute($input, $output);
    }
}