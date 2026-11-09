<?php

namespace Clicalmani\Queue\Contracts;

interface NotificationJobInterface extends JobInterface
{
    public function notification(): object;

    public function notifiable(): object;
}