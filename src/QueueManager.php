<?php

namespace Clicalmani\Queue;

use Clicalmani\Queue\Contracts\QueueDriver;
use Clicalmani\Queue\Drivers\{
    SyncQueueDriver,
    DatabaseQueueDriver,
    RedisQueueDriver,
};

final class QueueManager
{
    private QueueDriver $driver;

    public function __construct(?QueueDriver $driver = null)
    {
        $this->driver = $driver ?? new SyncQueueDriver();
    }

    /**
     * Pousse un job dans la queue.
     */
    public function push(NotificationJob $job, int $delay = 0): string
    {
        $payload = serialize($job);

        return $this->driver->push($job->queue(), $payload, $delay);
    }

    /**
     * Récupère et exécute le prochain job d'une queue (utilisé par le worker).
     */
    public function processNext(string $queue = 'default'): bool
    {
        $record = $this->driver->pop($queue);

        if ($record === null) {
            return false;
        }

        try {
            $job = unserialize($record['payload']);

            if (! $job instanceof NotificationJob) {
                $this->driver->fail($record['id'], 'Invalid job payload.');
                return true;
            }

            $job->handle();
            $this->driver->delete($record['id']);

            return true;
        } catch (\Throwable $e) {
            $this->handleFailure($record, $job ?? null, $e);
            return true;
        }
    }

    private function handleFailure(array $record, ?NotificationJob $job, \Throwable $e): void
    {
        $maxTries = $job?->tries() ?? 1;

        if ($record['attempts'] < $maxTries) {
            $backoff = $job?->backoff() ?? 5;
            $delay   = is_array($backoff)
                ? ($backoff[$record['attempts'] - 1] ?? end($backoff))
                : $backoff;

            $this->driver->release($record['id'], $delay);
        } else {
            $this->driver->fail($record['id'], $e->getMessage());
            $job?->failed($e);
        }
    }

    public function size(string $queue = 'default'): int
    {
        return $this->driver->size($queue);
    }

    public function driver(): QueueDriver { return $this->driver; }

    public function setDriver(QueueDriver $driver): self
    {
        $this->driver = $driver;
        return $this;
    }
}