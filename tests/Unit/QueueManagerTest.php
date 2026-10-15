<?php

namespace Queue\Test\Unit;

use PHPUnit\Framework\TestCase;
use Clicalmani\Queue\{
    QueueManager,
    NotificationJob,
};
use Clicalmani\Queue\Contracts\QueueDriver;
use Test\Fixtures\Queue\FakeJob;
use Test\Fixtures\Queue\ConfigurableNotification;
use Test\Fixtures\Notifiables\FakeNotifiable;

class QueueManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeJob::reset();
    }

    // ─────────────────────────────────────────────────────────────
    // Push
    // ─────────────────────────────────────────────────────────────

    public function test_push_delegates_to_driver(): void
    {
        $driver = $this->recordingDriver();
        $manager = new QueueManager($driver);

        $job = new FakeJob(new ConfigurableNotification(), new FakeNotifiable());
        $id = $manager->push($job);

        $this->assertCount(1, $driver->pushed);
        $this->assertSame('default', $driver->pushed[0]['queue']);
        $this->assertSame($id, $driver->pushed[0]['id']);
    }

    public function test_push_with_delay_passes_through(): void
    {
        $driver = $this->recordingDriver();
        $manager = new QueueManager($driver);

        $job = new FakeJob(new ConfigurableNotification(), new FakeNotifiable());
        $manager->push($job, delay: 300);

        $this->assertSame(300, $driver->pushed[0]['delay']);
    }

    public function test_push_uses_job_queue_name(): void
    {
        $driver = $this->recordingDriver();
        $manager = new QueueManager($driver);

        $notification = new ConfigurableNotification(queueName: 'high');
        $job = new FakeJob($notification, new FakeNotifiable());

        $manager->push($job);

        $this->assertSame('high', $driver->pushed[0]['queue']);
    }

    // ─────────────────────────────────────────────────────────────
    // ProcessNext
    // ─────────────────────────────────────────────────────────────

    public function test_process_next_returns_false_when_empty(): void
    {
        $driver = $this->emptyDriver();
        $manager = new QueueManager($driver);

        $this->assertFalse($manager->processNext());
    }

    public function test_process_next_executes_job_and_deletes(): void
    {
        $job = new FakeJob(new ConfigurableNotification(), new FakeNotifiable());

        $driver = $this->driverWith($job);
        $manager = new QueueManager($driver);

        $this->assertTrue($manager->processNext());
        $this->assertCount(1, FakeJob::$executed);
        $this->assertCount(1, $driver->deleted);
    }

    public function test_process_next_releases_on_failure_when_attempts_remain(): void
    {
        $job = new FakeJob(new ConfigurableNotification(triesValue: 3), new FakeNotifiable());
        $job->throwOnHandle = true;
        $job->exception = new \RuntimeException('boom');

        $driver = $this->driverWith($job, attempts: 1);
        $manager = new QueueManager($driver);

        $manager->processNext();

        $this->assertCount(1, $driver->released);
        $this->assertEmpty($driver->failed);
    }

    public function test_process_next_fails_job_when_attempts_exhausted(): void
    {
        $job = new FakeJob(new ConfigurableNotification(triesValue: 3), new FakeNotifiable());
        $job->throwOnHandle = true;

        $driver = $this->driverWith($job, attempts: 3);
        $manager = new QueueManager($driver);

        $manager->processNext();

        $this->assertCount(1, $driver->failed);
        $this->assertCount(1, FakeJob::$failed);
    }

    public function test_process_next_uses_backoff_on_release(): void
    {
        $notification = new ConfigurableNotification(triesValue: 3, backoffValue: 42);
        $job = new FakeJob($notification, new FakeNotifiable());
        $job->throwOnHandle = true;

        $driver = $this->driverWith($job, attempts: 1);
        $manager = new QueueManager($driver);

        $manager->processNext();

        $this->assertSame(42, $driver->released[0]['delay']);
    }

    public function test_process_next_uses_backoff_array_indexed_by_attempt(): void
    {
        $notification = new ConfigurableNotification(
            triesValue: 5,
            backoffValue: [10, 30, 60, 120]
        );
        $job = new FakeJob($notification, new FakeNotifiable());
        $job->throwOnHandle = true;

        // Tentative 2 → backoff[1] = 30
        $driver = $this->driverWith($job, attempts: 2);
        $manager = new QueueManager($driver);

        $manager->processNext();

        $this->assertSame(30, $driver->released[0]['delay']);
    }

    // ─────────────────────────────────────────────────────────────
    // Size
    // ─────────────────────────────────────────────────────────────

    public function test_size_delegates_to_driver(): void
    {
        $driver = $this->recordingDriver();
        $driver->sizeValue = 42;

        $manager = new QueueManager($driver);

        $this->assertSame(42, $manager->size('default'));
    }

    // ─────────────────────────────────────────────────────────────
    // Driver accessor
    // ─────────────────────────────────────────────────────────────

    public function test_driver_returns_current(): void
    {
        $driver = $this->recordingDriver();
        $manager = new QueueManager($driver);

        $this->assertSame($driver, $manager->driver());
    }

    public function test_set_driver_replaces(): void
    {
        $a = $this->recordingDriver();
        $b = $this->recordingDriver();

        $manager = new QueueManager($a);
        $manager->setDriver($b);

        $this->assertSame($b, $manager->driver());
    }

    // ─────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────

    private function recordingDriver(): RecordingQueueDriver
    {
        return new RecordingQueueDriver();
    }

    private function emptyDriver(): RecordingQueueDriver
    {
        return new RecordingQueueDriver();
    }

    private function driverWith(FakeJob $job, int $attempts = 1): RecordingQueueDriver
    {
        $driver = new RecordingQueueDriver();
        $driver->nextJob = [
            'id'       => 'id-1',
            'payload'  => serialize($job),
            'attempts' => $attempts,
        ];

        return $driver;
    }
}

// ─── Fixture driver ──────────────────────────────────────────────

class RecordingQueueDriver implements QueueDriver
{
    public array $pushed   = [];
    public array $deleted  = [];
    public array $released = [];
    public array $failed   = [];
    public ?array $nextJob = null;
    public int $sizeValue  = 0;

    public function push(string $queue, string $payload, int $delay = 0): string
    {
        $id = 'job-' . (count($this->pushed) + 1);
        $this->pushed[] = compact('id', 'queue', 'payload', 'delay');
        return $id;
    }

    public function pop(string $queue): ?array
    {
        $job = $this->nextJob;
        $this->nextJob = null;
        return $job;
    }

    public function delete(string $id): void
    {
        $this->deleted[] = $id;
    }

    public function release(string $id, int $delay = 0): void
    {
        $this->released[] = compact('id', 'delay');
    }

    public function fail(string $id, string $reason, ?string $class = null): void
    {
        $this->failed[] = compact('id', 'reason', 'class');
    }

    public function size(string $queue): int
    {
        return $this->sizeValue;
    }
}