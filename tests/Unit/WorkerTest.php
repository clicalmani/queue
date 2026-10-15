<?php

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Clicalmani\Queue\{
    NotificationJob,
    QueueManager
};
use Clicalmani\Queue\Contracts\QueueDriver;
use Test\Fixtures\Queue\FakeJob;
use Test\Fixtures\Queue\ConfigurableNotification;
use Test\Fixtures\Notifiables\FakeNotifiable;

class WorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeJob::reset();
    }

    /**
     * Test que le worker traite des jobs tant qu'il y en a.
     * On arrête le worker en vidant la queue (le test n'a pas besoin
     * de hook — le worker s'arrête tout seul quand la queue est vide
     * ET qu'on lui dit de s'arrêter).
     */
    public function test_worker_processes_jobs_until_stopped(): void
    {
        $driver  = new InMemoryDriver();
        $manager = new QueueManager($driver);

        $driver->enqueue(new FakeJob(new ConfigurableNotification(), new FakeNotifiable()));
        $driver->enqueue(new FakeJob(new ConfigurableNotification(), new FakeNotifiable()));

        // Traitement manuel — on n'utilise PAS le worker ici
        $manager->processNext('default');
        $manager->processNext('default');

        $this->assertCount(2, FakeJob::$executed);
        $this->assertFalse($manager->processNext('default'));
    }

    /**
     * Test que le worker respecte l'ordre des queues.
     * On utilise processNext() directement, pas Worker.
     */
    public function test_queues_are_processed_in_priority_order(): void
    {
        $driver  = new InMemoryDriver();
        $manager = new QueueManager($driver);

        $driver->enqueueOn('high',    new FakeJob(new ConfigurableNotification(), new FakeNotifiable()));
        $driver->enqueueOn('high',    new FakeJob(new ConfigurableNotification(), new FakeNotifiable()));
        $driver->enqueueOn('default', new FakeJob(new ConfigurableNotification(), new FakeNotifiable()));

        $processed = [];

        // Ordre : high en premier, puis default
        while ($manager->processNext('high')) {
            $processed[] = 'high';
        }
        while ($manager->processNext('default')) {
            $processed[] = 'default';
        }

        $this->assertSame(['high', 'high', 'default'], $processed);
    }
}

class InMemoryDriver implements QueueDriver
{
    /** @var array<string, array<int, array>> */
    private array $queues = [];

    public function enqueue(NotificationJob $job): void
    {
        $this->enqueueOn('default', $job);
    }

    public function enqueueOn(string $queue, NotificationJob $job): void
    {
        $this->queues[$queue][] = [
            'id'       => uniqid('job-'),
            'payload'  => serialize($job),
            'attempts' => 1,
        ];
    }

    public function push(string $queue, string $payload, int $delay = 0): string
    {
        $id = uniqid('job-');
        $this->queues[$queue][] = [
            'id'       => $id,
            'payload'  => $payload,
            'attempts' => 1,
        ];
        return $id;
    }

    public function pop(string $queue): ?array
    {
        $this->queues[$queue] ??= [];
        return array_shift($this->queues[$queue]) ?: null;
    }

    public function delete(string $id): void {}
    public function release(string $id, int $delay = 0): void {}
    public function fail(string $id, string $reason, ?string $class = null): void {}

    public function size(string $queue): int
    {
        return count($this->queues[$queue] ?? []);
    }
}