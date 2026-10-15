<?php
namespace Queue\Test\Fixtures;

use Clicalmani\Database\Contracts\PreparedStatementInterface;

/**
 * Simule un PDOStatement préparé pour les tests unitaires.
 *
 * Permet de :
 *  - capturer tous les bind() effectués
 *  - simuler des succès/échecs d'execute()
 *  - retourner un PDOStatement "fake" ou null via unwrap()
 */
final class FakePreparedStatement implements PreparedStatementInterface
{
    /** @var array<string|int, array{value: mixed, type: int}> */
    public array $bindings = [];

    /** Nombre de fois où execute() a été appelé. */
    public int $executeCount = 0;

    /** Si vrai, execute() retournera false. */
    public bool $shouldFail = false;

    /** Message d'erreur simulé. */
    public string $errorMessage = 'Simulated prepare execute failure';

    /** Code d'erreur simulé. */
    public int $errorCode = 1;

    public function __construct(
        private FakeDatabaseConnection $connection,
        private string $sql,
    ) {}

    public function bind(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): static
    {
        $key = is_int($param) ? $param : ':' . ltrim($param, ':');

        $this->bindings[$key] = [
            'value' => $value,
            'type'  => $type,
        ];

        return $this;
    }

    public function bindAll(array $bindings): static
    {
        foreach ($bindings as $key => $entry) {
            if (is_array($entry)) {
                [$value, $type] = $entry + [null, \PDO::PARAM_STR];
            } else {
                $value = $entry;
                $type  = \PDO::PARAM_STR;
            }

            $this->bind($key, $value, $type);
        }

        return $this;
    }

    public function execute(): bool
    {
        $this->executeCount++;

        if ($this->shouldFail || $this->connection->shouldFail) {
            throw new \PDOException($this->errorMessage, $this->errorCode);
        }

        return true;
    }

    public function unwrap(): ?\PDOStatement
    {
        // Retourne null : les tests unitaires n'ont pas besoin d'un vrai PDOStatement.
        return null;
    }

    // -----------------------------------------------------------------
    //  Helpers pour les tests
    // -----------------------------------------------------------------

    /**
     * Vrai si la clé de binding existe (avec ou sans ":").
     */
    public function hasBinding(string $key): bool
    {
        $key = ':' . ltrim($key, ':');
        return array_key_exists($key, $this->bindings);
    }

    /**
     * Retourne la valeur bindée pour une clé donnée.
     */
    public function getBindingValue(string $key): mixed
    {
        $key = ':' . ltrim($key, ':');
        return $this->bindings[$key]['value'] ?? null;
    }

    /**
     * Retourne le type PDO bindé pour une clé donnée.
     */
    public function getBindingType(string $key): ?int
    {
        $key = ':' . ltrim($key, ':');
        return $this->bindings[$key]['type'] ?? null;
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}