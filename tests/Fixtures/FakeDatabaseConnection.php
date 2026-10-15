<?php
namespace Queue\Test\Fixtures;

use Clicalmani\Database\Contracts\PreparedStatementInterface;
use Clicalmani\Database\Contracts\QueryExecutorInterface;
use Queue\Test\Fixtures\FakePreparedStatement;

/**
 * Simule une connexion PDO pour les tests unitaires.
 *
 * Permet de :
 *  - simuler des succès/échecs d'exécution
 *  - retourner des lignes de résultat
 *  - simuler un lastInsertId()
 *  - simuler un rowCount() / affectedRows
 *  - simuler la version du serveur (MySQL 5.7 / 8.0.19+ / MariaDB)
 *  - logger toutes les requêtes exécutées
 */
final class FakeDatabaseConnection implements QueryExecutorInterface
{
    // -----------------------------------------------------------------
    //  Configuration
    // -----------------------------------------------------------------

    /** Version du serveur retournée par getAttribute(PDO::ATTR_SERVER_VERSION). */
    public string $serverVersion = '8.0.30';

    /** Valeur retournée par lastInsertId(). */
    public int|string|null $lastInsertIdValue = null;

    /** Valeur retournée par rowCount() après un execute(). */
    public int $affectedRows = 0;

    /** Nombre de lignes retournées par foundRows(). */
    public int $foundRowsValue = 0;

    /** Si vrai, la prochaine requête lèvera une PDOException. */
    public bool $shouldFail = false;

    /** Message d'erreur simulé. */
    public string $errorMessage = 'Simulated failure';

    /** Code d'erreur simulé. */
    public int $errorCode = 1;

    /**
     * Séquence de résultats pour les appels successifs à execute().
     * Ex: [true, false, true] → 1er réussit, 2e échoue, 3e réussit.
     * Si vide, utilise $shouldFail.
     */
    public array $executeResults = [];

    /** Lignes retournées par fetch() (pour les SELECT). */
    public array $rows = [];

    /**
     * File d'attente de PreparedStatement à retourner par prepare().
     * Si vide, un nouveau FakePreparedStatement est créé à la volée.
     */
    public array $preparedQueue = [];

    /** Dernier PreparedStatement créé (utile pour les assertions). */
    public ?FakePreparedStatement $lastPrepared = null;

    // -----------------------------------------------------------------
    //  Journal
    // -----------------------------------------------------------------

    /** @var array<int, array{type: string, sql: string, params: array}> */
    public array $log = [];

    public function reset(): void
    {
        $this->shouldFail         = false;
        $this->executeResults     = [];
        $this->rows               = [];
        $this->preparedQueue      = [];
        $this->lastPrepared       = null;
        $this->log                = [];
        $this->affectedRows       = 0;
        $this->lastInsertIdValue  = null;
        $this->foundRowsValue     = 0;
    }

    // -----------------------------------------------------------------
    //  QueryExecutorInterface
    // -----------------------------------------------------------------

    public function query(string $sql, array $options = [], array $params = []): ?\PDOStatement
    {
        $this->log[] = ['type' => 'query', 'sql' => $sql, 'params' => $params];

        if ($this->shouldFail) {
            throw new \PDOException($this->errorMessage, $this->errorCode);
        }

        // Retourne null : les tests qui vérifient le SQL n'ont pas besoin
        // d'un vrai PDOStatement. Les tests de fetch utiliseront un fake
        // spécifique si nécessaire.
        return null;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $this->log[] = ['type' => 'execute', 'sql' => $sql, 'params' => $params];

        // Consomme la file de résultats si définie
        if (!empty($this->executeResults)) {
            $result = array_shift($this->executeResults);

            if ($result === false) {
                return false;
            }

            return true;
        }

        if ($this->shouldFail) {
            throw new \PDOException($this->errorMessage, $this->errorCode);
        }

        return true;
    }

    public function prepare(string $sql, array $options = [], array $bindings = []): PreparedStatementInterface
    {
        $this->log[] = ['type' => 'prepare', 'sql' => $sql, 'params' => $options];

        // File d'attente explicite ?
        if (!empty($this->preparedQueue)) {
            $prepared = array_shift($this->preparedQueue);
            $this->lastPrepared = $prepared;
            return $prepared;
        }

        // Sinon, on crée un fake lié à cette connexion
        $prepared = new FakePreparedStatement($this, $sql);
        $this->lastPrepared = $prepared;

        return $prepared;
    }

    public function numRows(?\PDOStatement $stmt): int
    {
        return $this->affectedRows;
    }

    public function foundRows(): int
    {
        return $this->foundRowsValue;
    }

    public function fetch(\PDOStatement $stmt, int $mode = \PDO::FETCH_OBJ): mixed
    {
        if (empty($this->rows)) {
            return false;
        }

        $row = array_shift($this->rows);

        if ($mode === \PDO::FETCH_OBJ) {
            return (object) $row;
        }

        return $row;
    }

    public function lastInsertId(): int|string|null
    {
        return $this->lastInsertIdValue;
    }

    public function errno(): int
    {
        return $this->shouldFail ? $this->errorCode : 0;
    }

    public function error(): string
    {
        return $this->shouldFail ? $this->errorMessage : '';
    }

    // -----------------------------------------------------------------
    //  Helpers pour les tests
    // -----------------------------------------------------------------

    /**
     * Retourne toutes les requêtes de type 'query'.
     * @return string[]
     */
    public function queries(): array
    {
        return array_values(array_map(
            fn($e) => $e['sql'],
            array_filter($this->log, fn($e) => $e['type'] === 'query')
        ));
    }

    /**
     * Retourne toutes les requêtes de type 'execute'.
     * @return string[]
     */
    public function executions(): array
    {
        return array_values(array_map(
            fn($e) => $e['sql'],
            array_filter($this->log, fn($e) => $e['type'] === 'execute')
        ));
    }

    /**
     * Vrai si au moins une requête contient la sous-chaîne donnée.
     */
    public function hasQuery(string $needle): bool
    {
        foreach ($this->log as $entry) {
            if (str_contains($entry['sql'], $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nombre total d'entrées dans le log.
     */
    public function logCount(): int
    {
        return count($this->log);
    }
}