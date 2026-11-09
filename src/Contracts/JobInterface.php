<?php

namespace Clicalmani\Queue\Contracts;

use Throwable;

/**
 * Contract implemented by every job pushed onto a queue.
 *
 * Jobs are serialized with PHP's native serialize(): keep their state scalar
 * or serializable, and implement __serialize()/__unserialize() when needed.
 */
interface JobInterface extends ShouldQueue
{
    /**
     * Execute the job.
     */
    public function handle(): void;

    /**
     * Called once the job has exhausted its retries.
     */
    public function failed(Throwable $e): void;
}
