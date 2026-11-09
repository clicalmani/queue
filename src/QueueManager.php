<?php

namespace Clicalmani\Queue;

use Clicalmani\Queue\Contracts\JobInterface;
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
    public function push(JobInterface $job, int $delay = 0): string
    {
        $payload = serialize($job);

        return $this->driver->push($job->queue(), $payload, $delay);
    }

    /**
     * Récupère et exécute le prochain job d'une queue (utilisé par le worker).
     *
     * @return bool Vrai si un job a été prélevé (qu'il ait abouti ou non).
     */
    public function processNext(string $queue = 'default'): bool
    {
        return $this->processNextJob($queue) !== null;
    }

    /**
     * Récupère et exécute le prochain job d'une queue.
     *
     * @return array{id: string, processed: bool}|null Le job prélevé et son
     *         résultat, null si la queue est vide.
     */
    public function processNextJob(string $queue = 'default'): ?array
    {
        $record = $this->driver->pop($queue);

        if ($record === null) {
            return null;
        }

        try {
            $job = unserialize($record['payload']);

            if (! $job instanceof JobInterface) {
                $this->driver->fail($record['id'], 'Invalid job payload.');
                return ['id' => (string) $record['id'], 'processed' => false];
            }

            $job->handle();
            $this->driver->delete($record['id']);

            return ['id' => (string) $record['id'], 'processed' => true];
        } catch (\Throwable $e) {
            $this->handleFailure($record, $job ?? null, $e);
            return ['id' => (string) $record['id'], 'processed' => false];
        }
    }

    private function handleFailure(array $record, ?JobInterface $job, \Throwable $e): void
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