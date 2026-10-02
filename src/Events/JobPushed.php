<?php

namespace Clicalmani\Queue\Events;

final class JobPushed
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly object $notification,
        public readonly object $notifiable,
    ) {}
}