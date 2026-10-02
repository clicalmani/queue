<?php

namespace Clicalmani\Queue\Drivers;

use Clicalmani\Queue\Contracts\QueueDriver;

final class RedisQueueDriver implements QueueDriver
{
    public function __construct(
        private $redis,
        private string $prefix = 'queue:',
    ) {}

    public function push(string $queue, string $payload, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));

        $record = json_encode([
            'id'      => $id,
            'payload' => base64_encode($payload),
        ]);

        $key = $this->prefix . $queue;

        if ($delay > 0) {
            $this->redis->zAdd($key . ':delayed', time() + $delay, $record);
        } else {
            $this->redis->lPush($key, $record);
        }

        return $id;
    }

    public function pop(string $queue): ?array
    {
        $key = $this->prefix . $queue;

        // Remet les jobs retardés dont l'heure est venue
        $now = time();
        $due = $this->redis->zRangeByScore($key . ':delayed', 0, $now);

        foreach ($due as $record) {
            $this->redis->zRem($key . ':delayed', $record);
            $this->redis->lPush($key, $record);
        }

        $record = $this->redis->rPop($key);

        if (! $record) {
            return null;
        }

        $data = json_decode($record, true);

        return [
            'id'       => $data['id'],
            'payload'  => base64_decode($data['payload']),
            'attempts' => 1,
        ];
    }

    public function delete(string $id): void
    {
        $this->redis->del($this->prefix . 'reserved:' . $id);
    }

    public function release(string $id, int $delay = 0): void
    {
        // Simplifié — en prod on restaurerait le payload d'origine
    }

    public function fail(string $id, string $reason): void
    {
        $this->redis->lPush($this->prefix . 'failed', json_encode([
            'id'     => $id,
            'reason' => $reason,
            'at'     => time(),
        ]));
    }

    public function size(string $queue): int
    {
        return (int) $this->redis->lLen($this->prefix . $queue);
    }
}