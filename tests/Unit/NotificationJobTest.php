<?php

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Clicalmani\Queue\NotificationJob;
use Test\Fixtures\Queue\ConfigurableNotification;
use Test\Fixtures\Notifiables\FakeNotifiable;

class NotificationJobTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Construction
    // ─────────────────────────────────────────────────────────────

    public function test_constructor_stores_notification_and_notifiable(): void
    {
        $notification = new ConfigurableNotification();
        $notifiable   = new FakeNotifiable();

        $job = new NotificationJob($notification, $notifiable);

        $this->assertSame($notification, $job->notification());
        $this->assertSame($notifiable, $job->notifiable());
    }

    // ─────────────────────────────────────────────────────────────
    // Sérialisation
    // ─────────────────────────────────────────────────────────────

    public function test_serialization_roundtrip(): void
    {
        $notification = new ConfigurableNotification(queueName: 'high');
        $notifiable   = new FakeNotifiable();
        $notifiable->id = 42;

        $job = new NotificationJob($notification, $notifiable);

        $serialized = serialize($job);
        $restored   = unserialize($serialized);

        $this->assertInstanceOf(NotificationJob::class, $restored);
        $this->assertInstanceOf(ConfigurableNotification::class, $restored->notification());
        $this->assertSame('high', $restored->notification()->queueName);
        $this->assertSame(42, $restored->notifiable()->id);
    }

    public function test_serialization_preserves_options(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(),
            new FakeNotifiable(),
            options: [
                'queue'    => 'high',
                'tries'    => 5,
                'backoff'  => 30,
                'timeout'  => 60,
                'delay'    => 120,
                'attempts' => 2,
            ],
        );

        $restored = unserialize(serialize($job));

        $this->assertSame('high', $restored->queue());
        $this->assertSame(5, $restored->tries());
        $this->assertSame(30, $restored->backoff());
        $this->assertSame(60, $restored->timeout());
        $this->assertSame(120, $restored->delay());
        $this->assertSame(2, $restored->attempts());
    }

    // ─────────────────────────────────────────────────────────────
    // Options — queue()
    // ─────────────────────────────────────────────────────────────

    public function test_queue_uses_option_override(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(queueName: 'low'),
            new FakeNotifiable(),
            options: ['queue' => 'high'],
        );

        $this->assertSame('high', $job->queue());
    }

    public function test_queue_uses_notification_method_when_no_option(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(queueName: 'notifications'),
            new FakeNotifiable(),
        );

        $this->assertSame('notifications', $job->queue());
    }

    public function test_queue_falls_back_to_default(): void
    {
        $notification = new class {
            public function via($n = null): array { return ['database']; }
        };

        $job = new NotificationJob($notification, new FakeNotifiable());

        $this->assertSame('default', $job->queue());
    }

    // ─────────────────────────────────────────────────────────────
    // Options — tries() / backoff() / timeout()
    // ─────────────────────────────────────────────────────────────

    public function test_tries_uses_option_override(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(triesValue: 3),
            new FakeNotifiable(),
            options: ['tries' => 10],
        );

        $this->assertSame(10, $job->tries());
    }

    public function test_tries_uses_notification_method(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(triesValue: 7),
            new FakeNotifiable(),
        );

        $this->assertSame(7, $job->tries());
    }

    public function test_tries_defaults_to_three(): void
    {
        $job = new NotificationJob(new \stdClass(), new FakeNotifiable());

        $this->assertSame(3, $job->tries());
    }

    public function test_backoff_accepts_integer(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(backoffValue: 10),
            new FakeNotifiable(),
        );

        $this->assertSame(10, $job->backoff());
    }

    public function test_backoff_accepts_array(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(backoffValue: [10, 30, 60]),
            new FakeNotifiable(),
        );

        $this->assertSame([10, 30, 60], $job->backoff());
    }

    public function test_backoff_defaults_to_five(): void
    {
        $job = new NotificationJob(new \stdClass(), new FakeNotifiable());

        $this->assertSame(5, $job->backoff());
    }

    public function test_timeout_uses_option_override(): void
    {
        $job = new NotificationJob(
            new ConfigurableNotification(timeoutValue: 30),
            new FakeNotifiable(),
            options: ['timeout' => 120],
        );

        $this->assertSame(120, $job->timeout());
    }

    public function test_timeout_defaults_to_thirty(): void
    {
        $job = new NotificationJob(new \stdClass(), new FakeNotifiable());

        $this->assertSame(30, $job->timeout());
    }

    // ─────────────────────────────────────────────────────────────
    // Options — delay / attempts
    // ─────────────────────────────────────────────────────────────

    public function test_delay_defaults_to_zero(): void
    {
        $job = new NotificationJob(new \stdClass(), new FakeNotifiable());

        $this->assertSame(0, $job->delay());
    }

    public function test_attempts_defaults_to_zero(): void
    {
        $job = new NotificationJob(new \stdClass(), new FakeNotifiable());

        $this->assertSame(0, $job->attempts());
    }
}