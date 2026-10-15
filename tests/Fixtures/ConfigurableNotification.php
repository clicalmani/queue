<?php

namespace Queue\Test\Fixtures;

class ConfigurableNotification
{
    public function __construct(
        public string $queueName = 'default',
        public int $triesValue = 3,
        public int|array $backoffValue = 5,
        public int $timeoutValue = 30,
    ) {}

    public function via($notifiable = null): array
    {
        return ['database'];
    }

    public function queue(): string          { return $this->queueName; }
    public function tries(): int             { return $this->triesValue; }
    public function backoff(): int|array     { return $this->backoffValue; }
    public function timeout(): int           { return $this->timeoutValue; }
}