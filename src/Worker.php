<?php

namespace Clicalmani\Queue;

use Clicalmani\Queue\Events\JobProcessed;

final class Worker
{
    private bool $running = true;

    public function __construct(
        private QueueManager $queue,
        private int $sleep = 1,       // secondes entre deux polls vides
        private int $maxMemory = 128, // MB
    ) {}

    public function run(array $queues = ['default']): void
    {
        // Signaux pour arrêt propre
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT,  fn() => $this->stop());

        logger()->info('Worker started', ['queues' => $queues]);

        while ($this->running) {
            $processed = false;

            foreach ($queues as $queue) {
                if ($this->queue->processNext($queue)) {
                    $processed = true;
                    event(new JobProcessed($queue, $queue));
                }
            }

            if (! $processed) {
                sleep($this->sleep);
            }

            if ($this->memoryExceeded()) {
                logger()->warning('Worker memory limit reached, exiting.');
                $this->stop();
            }
        }

        logger()->info('Worker stopped');
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function memoryExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) > $this->maxMemory;
    }
}