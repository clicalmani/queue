<?php

namespace Queue\Test\Unit;

use PHPUnit\Framework\TestCase;
use Clicalmani\Queue\Drivers\SyncQueueDriver;
use Test\Fixtures\Queue\FakeJob;
use Test\Fixtures\Queue\ConfigurableNotification;
use Test\Fixtures\Notifiables\FakeNotifiable;

class SyncQueueDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeJob::reset();
    }

    public function test_push_executes_job_inline(): void
    {
        $job = new FakeJob(new ConfigurableNotification(), new FakeNotifiable());

        $driver = new SyncQueueDriver();
        $driver->push('default', serialize($job));

        $this->assertCount(1, FakeJob::$executed);
        $this->assertSame($job, FakeJob::$executed[0]);
    }

    public function test_push_returns_job_id(): void
    {
        $job = new FakeJob(new ConfigurableNotification(), new FakeNotifiable());

        $driver = new SyncQueueDriver();
        $id = $driver->push('default', serialize($job));

        $this->assertIsString($id);
        $this->assertSame(32, strlen($id)); // bin2hex(16 bytes) = 32 chars
    }

    public function test_push_with_invalid_payload_throws(): void
    {
        $driver = new SyncQueueDriver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid job payload');

        $driver->push('default', serialize(new \stdClass()));
    }

    public function test_push_ignores_delay_and_executes_immediately(): void
    {
        $job = new FakeJob(new ConfigurableNotification(), new FakeNotifiable());

        $driver = new SyncQueueDriver();
        $driver->push('default', serialize($job), delay: 300);

        // Même avec un délai, l'exécution est immédiate en mode sync
        $this->assertCount(1, FakeJob::$executed);
    }

    public function test_pop_always_returns_null(): void
    {
        $driver = new SyncQueueDriver();

        $this->assertNull($driver->pop('default'));
        $this->assertNull($driver->pop('high'));
    }

    public function test_size_is_always_zero(): void
    {
        $driver = new SyncQueueDriver();

        $this->assertSame(0, $driver->size('default'));
        $this->assertSame(0, $driver->size('high'));
    }

    public function test_delete_release_fail_are_noop(): void
    {
        $driver = new SyncQueueDriver();

        // Ne doivent pas lever d'exception
        $driver->delete('any-id');
        $driver->release('any-id', 10);
        $driver->fail('any-id', 'error message');

        $this->assertTrue(true); // pas d'exception = succès
    }
}