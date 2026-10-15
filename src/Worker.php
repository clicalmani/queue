<?php

declare(strict_types=1);

namespace Clicalmani\Queue;

use Clicalmani\Queue\Events\JobProcessed;

final class Worker
{
    private bool $running = true;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private QueueManager $queue,
        private int $sleep = 1,            // secondes entre deux polls vides
        private int $maxMemory = 128,      // MB
        private int $timeout = 60,         // secondes max par job (0 = illimité)
        private bool $once = false,        // traite un seul cycle puis s'arrête
        private bool $stopWhenEmpty = false,
        private ?string $lockFile = null,  // verrou anti-doublon (flock), null = désactivé
        private ?string $restartFile = null // défaut : temp/restart_worker.txt
    ) {}

    public function run(array $queues = ['default']): void
    {
        if (!function_exists('pcntl_async_signals')) {
            throw new \RuntimeException('L\'extension pcntl est requise pour le worker.');
        }

        // Un seul worker par verrou : cron peut relancer le script sans créer de doublons
        if (!$this->acquireLock()) {
            logger()->info('Worker already running, exiting.');
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT,  fn () => $this->stop());
        pcntl_signal(SIGALRM, function () {
            logger()->error('Job timeout exceeded, exiting.', ['timeout' => $this->timeout]);
            exit(1); // cron relancera un worker propre
        });

        $startedAt   = time();
        $restartFile = $this->restartFile ?? root_path('temp/restart_worker.txt');

        logger()->info('Worker started', ['queues' => $queues, 'pid' => getmypid()]);

        try {
            while ($this->running) {
                $processed = false;

                foreach ($queues as $queue) {
                    if (!$this->running) {
                        break; // signal reçu entre deux queues
                    }

                    if ($this->timeout > 0) {
                        pcntl_alarm($this->timeout);
                    }

                    try {
                        $job = $this->queue->processNext($queue);
                    } finally {
                        pcntl_alarm(0);
                    }

                    if ($job) {
                        $processed = true;
                        // Si processNext() renvoie un bool, adaptez le 2e argument
                        event(new JobProcessed($queue, $job));
                    }
                }

                if ($this->once || ($this->stopWhenEmpty && !$processed)) {
                    $this->stop();
                    break;
                }

                if (!$processed && $this->running) {
                    sleep($this->sleep); // interrompu immédiatement par SIGTERM
                }

                // Signal de redémarrage : le fichier n'est pas supprimé,
                // donc tous les workers démarrés avant sa modification s'arrêtent.
                clearstatcache(true, $restartFile);
                if (is_file($restartFile) && filemtime($restartFile) > $startedAt) {
                    logger()->info('Restart signal received — stopping the worker.');
                    $this->stop();
                }

                if ($this->memoryExceeded()) {
                    logger()->warning('Worker memory limit reached, exiting.');
                    $this->stop();
                }
            }
        } finally {
            $this->releaseLock();
        }

        logger()->info('Worker stopped', ['pid' => getmypid()]);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function memoryExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) > $this->maxMemory;
    }

    private function acquireLock(): bool
    {
        if ($this->lockFile === null) {
            return true;
        }

        $handle = fopen($this->lockFile, 'c');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Impossible d\'ouvrir le verrou : %s', $this->lockFile));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}