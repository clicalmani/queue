<?php

namespace Clicalmani\Queue\Contracts;

interface QueueDriver
{
    /**
     * Pousse un job dans la queue. Retourne un identifiant unique.
     */
    public function push(string $queue, string $payload, int $delay = 0): string;

    /**
     * Récupère le prochain job disponible (null si vide).
     *
     * @return array{id: string, payload: string, attempts: int}|null
     */
    public function pop(string $queue): ?array;

    /**
     * Marque un job comme terminé.
     */
    public function delete(string $id): void;

    /**
     * Remet un job en queue après échec.
     */
    public function release(string $id, int $delay = 0): void;

    /**
     * Marque un job comme définitivement échoué.
     */
    public function fail(string $id, string $reason): void;

    /**
     * Taille actuelle de la queue.
     */
    public function size(string $queue): int;
}