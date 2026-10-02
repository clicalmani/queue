<?php

namespace Clicalmani\Queue\Contracts;

interface ShouldQueue
{
    /**
     * Nom de la queue cible. 'default' si non défini.
     */
    public function queue(): string;

    /**
     * Nombre maximum de tentatives.
     */
    public function tries(): int;

    /**
     * Délai en secondes avant nouvelle tentative (backoff simple ou progressif).
     *
     * @return int|int[]
     */
    public function backoff(): int|array;

    /**
     * Timeout en secondes pour l'exécution du job.
     */
    public function timeout(): int;
}