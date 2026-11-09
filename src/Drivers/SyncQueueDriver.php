<?php

namespace Clicalmani\Queue\Drivers;

use Clicalmani\Queue\Contracts\JobInterface;
use Clicalmani\Queue\Contracts\QueueDriver;

final class SyncQueueDriver implements QueueDriver
{
    public function push(string $queue, string $payload, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));

        $job = unserialize($payload);

        if (! $job instanceof JobInterface) {
            throw new \RuntimeException('Invalid job payload.');
        }

        // Exécution inline — pas de delay respecté en mode sync
        $job->handle();

        return $id;
    }

    public function pop(string $queue): ?array { return null; }
    public function delete(string $id): void {}
    public function release(string $id, int $delay = 0): void {}
    public function fail(string $id, string $reason): void {}
    public function size(string $queue): int { return 0; }
}