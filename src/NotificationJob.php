<?php

namespace Clicalmani\Queue;

use Clicalmani\Queue\Contracts\NotificationJobInterface;
use Clicalmani\Core\Support\Facades\Notification;
use Clicalmani\Database\Notifications\NotificationContext;
use Throwable;

class NotificationJob implements NotificationJobInterface
{
    private ?NotificationContext $context = null;

    public function __construct(
        private object $notification,
        private object $notifiable,
        private array $options = [],
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Exécution
    // ─────────────────────────────────────────────────────────────

    public function handle(): void
    {
        Notification::send($this->notification, $this->notifiable);
    }

    public function failed(Throwable $e): void
    {
        logger()->error('Queued notification failed', [
            'notification' => $this->notification::class,
            'notifiable'   => $this->notifiable::class,
            'error'        => $e->getMessage(),
        ]);

        // Émet un événement pour observabilité externe
        event(new \Clicalmani\Queue\Events\JobFailed(
            $this,
            $e
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // Sérialisation (crucial pour la queue)
    // ─────────────────────────────────────────────────────────────

    public function __serialize(): array
    {
        return [
            'notification' => serialize($this->notification),
            'notifiable'   => serialize($this->notifiable),
            'options'      => $this->options,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->notification = unserialize($data['notification']);
        $this->notifiable   = unserialize($data['notifiable']);
        $this->options      = $data['options'] ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    // Options de queue (déduites de la notification)
    // ─────────────────────────────────────────────────────────────

    public function queue(): string
    {
        return $this->options['queue']
            ?? (method_exists($this->notification, 'queue')
                ? $this->notification->queue()
                : 'default');
    }

    public function tries(): int
    {
        return $this->options['tries']
            ?? (method_exists($this->notification, 'tries')
                ? $this->notification->tries()
                : 3);
    }

    public function backoff(): int|array
    {
        return $this->options['backoff']
            ?? (method_exists($this->notification, 'backoff')
                ? $this->notification->backoff()
                : 5);
    }

    public function timeout(): int
    {
        return $this->options['timeout']
            ?? (method_exists($this->notification, 'timeout')
                ? $this->notification->timeout()
                : 30);
    }

    public function delay(): int
    {
        return $this->options['delay'] ?? 0;
    }

    public function attempts(): int
    {
        return $this->options['attempts'] ?? 0;
    }

    // ─────────────────────────────────────────────────────────────
    // Accesseurs
    // ─────────────────────────────────────────────────────────────

    public function notification(): object { return $this->notification; }
    public function notifiable(): object   { return $this->notifiable; }
    public function options(): array       { return $this->options; }
}