<?php

namespace Clicalmani\Queue;

use Clicalmani\Queue\Contracts\QueueDriver;
use Clicalmani\Queue\Drivers\{
    SyncQueueDriver,
    DatabaseQueueDriver,
    RedisQueueDriver,
};
use Clicalmani\Queue\QueueManager;
use RuntimeException;

final class QueueManagerFactory
{
    /**
     * Crée un QueueManager à partir d'un tableau de configuration.
     *
     * @param array{
     *     connection: string,
     *     connections: array<string, array<string, mixed>>
     * } $config
     * @param object|null $container  Conteneur IoC (pour résoudre 'redis')
     */
    public static function make(array $config): QueueManager
    {
        $connection = $config['connection'] ?? 'sync';
        $settings   = $config['connections'][$connection] ?? null;

        if ($settings === null) {
            throw new RuntimeException(
                "Queue connection [{$connection}] is not configured."
            );
        }

        $driver = self::makeDriver($settings);

        return new QueueManager($driver);
    }

    /**
     * Résout un driver à partir de sa configuration.
     *
     * @param array<string, mixed> $settings
     */
    public static function makeDriver(array $settings): QueueDriver
    {
        return match ($settings['driver'] ?? 'sync') {
            'sync'     => self::makeSync($settings),
            'database' => self::makeDatabase($settings),
            'redis'    => self::makeRedis($settings),
            default    => throw new RuntimeException(
                sprintf('Unsupported queue driver [%s].', $settings['driver'] ?? 'null')
            ),
        };
    }

    // ─────────────────────────────────────────────────────────────
    // Drivers individuels
    // ─────────────────────────────────────────────────────────────

    private static function makeSync(array $settings): SyncQueueDriver
    {
        return new SyncQueueDriver();
    }

    private static function makeDatabase(array $settings): DriversDatabaseQueueDriver
    {
        $table = $settings['table'] ?? 'jobs';

        if (! is_string($table) || $table === '') {
            throw new RuntimeException('Database queue driver requires a non-empty [table] option.');
        }

        return new DatabaseQueueDriver($table);
    }

    private static function makeRedis(array $settings): RedisQueueDriver
    {
        $predis = new \Predis\Client([
            'scheme'   => $settings['scheme']   ?? 'tcp',
            'host'     => $settings['host']     ?? '127.0.0.1',
            'port'     => $settings['port']     ?? 6379,
            'username' => $settings['username'] ?? null,
            'password' => $settings['password'] ?? null,
            'database' => $settings['database'] ?? 0,
        ], [
            'prefix' => $settings['prefix'] ?? '',
        ]);

        return new RedisQueueDriver($predis, $settings['prefix'] ?? 'queue:');
    }

    // ─────────────────────────────────────────────────────────────
    // Extensibilité — drivers custom
    // ─────────────────────────────────────────────────────────────

    /** @var array<string, callable(array, ?object): QueueDriverInterface> */
    private static array $extensions = [];

    /**
     * Enregistre un driver custom.
     *
     *     QueueManagerFactory::extend('sqs', fn($settings, $c) => new SqsQueueDriver(...));
     */
    public static function extend(string $driver, callable $factory): void
    {
        self::$extensions[$driver] = $factory;
    }

    /**
     * Résout un driver en tenant compte des extensions.
     */
    public static function resolve(array $settings): QueueDriver
    {
        $name = $settings['driver'] ?? 'sync';

        if (isset(self::$extensions[$name])) {
            return (self::$extensions[$name])($settings, container());
        }

        return self::makeDriver($settings);
    }

    public static function flushExtensions(): void
    {
        self::$extensions = [];
    }
}