<?php

namespace Clicalmani\Queue\Events;

use Throwable;

final class JobFailed
{
    public function __construct(
        public readonly object $job,
        public readonly Throwable $exception,
    ) {}
}