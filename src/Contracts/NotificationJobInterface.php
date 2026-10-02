<?php

namespace Clicalmani\Queue\Contracts;

interface NotificationJobInterface
{
    public function handle(): void;

    public function failed(\Throwable $e): void;

    public function notification(): object;

    public function notifiable(): object;
}