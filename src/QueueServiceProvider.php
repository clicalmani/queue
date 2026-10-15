<?php
namespace Clicalmani\Queue;

use Clicalmani\Core\Providers\ServiceProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServiceConfigurator;
use Override;

class QueueServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        app()->addService(
            \Clicalmani\Queue\QueueManager::class, 
            \Clicalmani\Queue\QueueManager::class,
            fn(ServiceConfigurator|DefaultsConfigurator $config) => $config->factory([
                \Clicalmani\Queue\QueueManagerFactory::class,
                'resolve'
            ])->args([
                config('queue', [])
            ]),
            ['queue', \Clicalmani\Queue\QueueManager::class]
        );

        // Register console command handlers
        foreach ([
            Console\MigrateCommand::class,
            Console\QueueWorkCommand::class,
            Console\WorkerKillCommand::class,
            Console\RestartCommand::class,
            Console\FailedShowCommand::class,
            Console\FailedRetryCommand::class,
        ] as $command) {
            app()->addCommand($command);
        }

        // Initialize console application when running under CLI mode
        if ( isConsoleMode() ) {
            app()->console->make();
        }
    }

    #[Override]
    public function boot(
        ?\Clicalmani\Core\Acme\Container $container = null
    ): void
    {
        if (file_exists(config_path('/queue.php'))) {
            app()->config->set('queue', require_once config_path('/queue.php'));
        }
    }
}