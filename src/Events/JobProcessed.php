<?php

namespace Clicalmani\Queue\Events;

final class JobProcessed
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
    ) {}
}