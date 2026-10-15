<?php

namespace Queue\Test\Fixtures;

use Clicalmani\Database\Contracts\PreparedStatementInterface;
use Clicalmani\Database\Contracts\QueryExecutorInterface;
use Clicalmani\Database\Execution\PdoPreparedStatement;
use Clicalmani\Database\Listeners\QueryListenerRegistry;

final class PdoBackedFakeConnection implements QueryExecutorInterface
{
    public function __construct(private readonly \PDO $pdo) {}

    public function query(string $sql, array $options = [], array $params = []): ?\PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function execute(string $sql, array $params = []): bool
    {
        return $this->pdo->exec($sql) !== false;
    }

    public function prepare(string $sql, array $options = [], array $bindings = []): PreparedStatementInterface
    {
        return new PdoPreparedStatement(
            $this->pdo->prepare($sql),
            $sql,
            QueryListenerRegistry::getInstance(),
        );
    }

    public function numRows(?\PDOStatement $stmt): int
    {
        return $stmt ? $stmt->rowCount() : 0;
    }

    public function foundRows(): int
    {
        return (int) $this->pdo->query('SELECT FOUND_ROWS()')?->fetchColumn();
    }

    public function fetch(\PDOStatement $stmt, int $mode = \PDO::FETCH_OBJ): mixed
    {
        return $stmt->fetch($mode);
    }

    public function lastInsertId(): int|string|null
    {
        $id = $this->pdo->lastInsertId();
        return ($id === false || $id === '') ? null : $id;
    }

    public function errno(): int
    {
        return (int) ($this->pdo->errorInfo()[1] ?? 0);
    }

    public function error(): string
    {
        return (string) ($this->pdo->errorInfo()[2] ?? '');
    }
}