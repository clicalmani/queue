<?php

namespace Queue\Test\Fixtures;

use Clicalmani\Queue\NotificationJob;
use Throwable;

class FakeJob extends NotificationJob
{
    public static array $executed = [];
    public static array $failed   = [];

    public bool $throwOnHandle = false;
    public ?Throwable $exception = null;

    /** @var string[] */
    public array $handleLog = [];

    public function handle(): void
    {
        if ($this->throwOnHandle) {
            throw $this->exception ?? new \RuntimeException('Job failed');
        }

        $this->handleLog[] = 'handled';
        self::$executed[] = $this;
    }

    public function failed(Throwable $e): void
    {
        self::$failed[] = $e;
    }

    public static function reset(): void
    {
        self::$executed = [];
        self::$failed   = [];
    }
}