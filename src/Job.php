<?php

namespace Clicalmani\Queue;

use Clicalmani\Queue\Contracts\JobInterface;
use Clicalmani\Queue\Events\JobFailed;
use Throwable;

/**
 * Base class for queue jobs, providing sensible defaults.
 *
 * Extend it for plain jobs; implement JobInterface directly when another
 * base class is required.
 */
abstract class Job implements JobInterface
{
    public function queue(): string
    {
        return 'default';
    }

    public function tries(): int
    {
        return 3;
    }

    public function backoff(): int|array
    {
        return 5;
    }

    public function timeout(): int
    {
        return 30;
    }

    public function failed(Throwable $e): void
    {
        logger()->error('Queued job failed', [
            'job'   => static::class,
            'error' => $e->getMessage(),
        ]);

        event(new JobFailed($this, $e));
    }
}
