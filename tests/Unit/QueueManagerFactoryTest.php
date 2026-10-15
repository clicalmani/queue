<?php

namespace Queue\Test\Unit;

use PHPUnit\Framework\TestCase;
use Clicalmani\Queue\{
    QueueManager,
    QueueManagerFactory,
};
use Clicalmani\Queue\Contracts\QueueDriver;
use Clicalmani\Queue\Drivers\{
    SyncQueueDriver,
    DatabaseQueueDriver,
};
use RuntimeException;

class QueueManagerFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        QueueManagerFactory::flushExtensions();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // make()
    // ─────────────────────────────────────────────────────────────

    public function test_make_with_sync_driver(): void
    {
        $manager = QueueManagerFactory::make([
            'connection'  => 'sync',
            'connections' => ['sync' => ['driver' => 'sync']],
        ]);

        $this->assertInstanceOf(QueueManager::class, $manager);
        $this->assertInstanceOf(SyncQueueDriver::class, $manager->driver());
    }

    public function test_make_with_database_driver(): void
    {
        $manager = QueueManagerFactory::make([
            'connection'  => 'database',
            'connections' => [
                'database' => ['driver' => 'database', 'table' => 'custom_jobs'],
            ],
        ]);

        $this->assertInstanceOf(DatabaseQueueDriver::class, $manager->driver());
    }

    public function test_make_throws_on_missing_connection_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing [connection]');

        QueueManagerFactory::make(['connections' => []]);
    }

    public function test_make_throws_on_unknown_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Queue connection [missing] is not defined');

        QueueManagerFactory::make([
            'connection'  => 'missing',
            'connections' => [],
        ]);
    }

    public function test_make_throws_on_missing_driver_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a [driver]');

        QueueManagerFactory::make([
            'connection'  => 'broken',
            'connections' => ['broken' => []],
        ]);
    }

    public function test_make_throws_on_unsupported_driver(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported queue driver');

        QueueManagerFactory::make([
            'connection'  => 'weird',
            'connections' => ['weird' => ['driver' => 'unknown-driver']],
        ]);
    }

    public function test_make_throws_on_empty_table_option(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty [table]');

        QueueManagerFactory::make([
            'connection'  => 'database',
            'connections' => ['database' => ['driver' => 'database', 'table' => '']],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Extensions
    // ─────────────────────────────────────────────────────────────

    public function test_extend_registers_custom_driver(): void
    {
        QueueManagerFactory::extend('fake', fn($settings, $container) => new SyncQueueDriver());

        $driver = QueueManagerFactory::resolve(['driver' => 'fake']);

        $this->assertInstanceOf(SyncQueueDriver::class, $driver);
    }

    public function test_extend_overrides_builtin(): void
    {
        QueueManagerFactory::extend('sync', fn() => new class implements QueueDriver {
            public function push(string $q, string $p, int $d = 0): string { return 'x'; }
            public function pop(string $q): ?array { return null; }
            public function delete(string $id): void {}
            public function release(string $id, int $d = 0): void {}
            public function fail(string $id, string $r, ?string $c = null): void {}
            public function size(string $q): int { return 999; }
        });

        $driver = QueueManagerFactory::resolve(['driver' => 'sync']);

        $this->assertSame(999, $driver->size('any'));
    }

    // ─────────────────────────────────────────────────────────────
    // Overrides
    // ─────────────────────────────────────────────────────────────

    public function test_override_replaces_entire_manager(): void
    {
        $custom = new QueueManager(new SyncQueueDriver());

        QueueManagerFactory::extend('sync', fn() => $custom);

        $result = QueueManagerFactory::make([
            'connection'  => 'sync',
            'connections' => ['sync' => ['driver' => 'sync']],
        ]);

        $this->assertSame($custom, $result);
    }

    // ─────────────────────────────────────────────────────────────
    // Flush
    // ─────────────────────────────────────────────────────────────

    public function test_flush_clears_extensions_and_overrides(): void
    {
        QueueManagerFactory::extend('fake', fn() => new SyncQueueDriver());
        QueueManagerFactory::extend('sync', fn() => new QueueManager(new SyncQueueDriver()));

        QueueManagerFactory::flushExtensions();

        // Une extension 'fake' ne doit plus exister
        $this->expectException(RuntimeException::class);
        QueueManagerFactory::resolve(['driver' => 'fake']);
    }
}