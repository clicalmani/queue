<?php

namespace Queue\Test\Fixtures\Notifiables;

use Clicalmani\Database\Traits\Notifiable;

class FakeNotifiable
{
    use Notifiable;

    public int $id = 1;

    /** @var array<string, mixed> */
    public array $routes = [];

    public function getTable(): string
    {
        return 'fake_notifiables';
    }

    public function routeNotificationFor(string $channel): mixed
    {
        return $this->routes[$channel] ?? null;
    }
}