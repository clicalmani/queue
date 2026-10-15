<?php

namespace Queue\Test\Unit;

use PHPUnit\Framework\TestCase;
use Clicalmani\Queue\Drivers\DatabaseQueueDriver;
use Clicalmani\Core\Support\Facades\DB;
use Test\Fixtures\Queue\FakeJob;

class DatabaseQueueDriverTest extends TestCase
{
    private DatabaseQueueDriver $driver;
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // PDO SQLite en mémoire
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        $this->createJobsTable();

        DB::swap($this->pdo);
        
        $this->driver = new DatabaseQueueDriver('jobs');
    }

    protected function tearDown(): void
    {
        FakeJob::reset();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Push
    // ─────────────────────────────────────────────────────────────

    public function test_push_inserts_row(): void
    {
        $payload = 'serialized-payload';
        $id = $this->driver->push('default', $payload);
        
        $this->assertNotEmpty($id);

        $row = $this->fetchById($id);
        
        $this->assertSame('default', $row['queue']);
        $this->assertSame($payload, $row['payload']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['reserved_at']);
        $this->assertNull($row['failed_at']);
    }

    public function test_push_with_delay_sets_future_available_at(): void
    {
        $id = $this->driver->push('default', 'payload', delay: 60);
        
        $row = $this->fetchById($id);
        $this->assertNotNull($row, "Le job #$id doit exister.");
        $this->assertNotNull($row['available_at'], 'available_at ne doit pas être null.');

        $availableAt = $this->parseTimestamp($row['available_at']);
        $now         = time();

        $this->assertGreaterThan(
            $now + 55,
            $availableAt,
            'available_at doit être à au moins 55s dans le futur.'
        );

        $this->assertLessThan(
            $now + 65,
            $availableAt,
            'available_at ne doit pas dépasser 65s dans le futur.'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Pop
    // ─────────────────────────────────────────────────────────────

    public function test_pop_returns_null_when_empty(): void
    {
        $this->assertNull($this->driver->pop('default'));
    }

    public function test_pop_returns_next_available_job(): void
    {
        $this->driver->push('default', 'first');
        $this->driver->push('default', 'second');
        
        $record = $this->driver->pop('default');
        
        $this->assertNotNull($record);
        $this->assertSame('first', $record['payload']);
        $this->assertSame(1, $record['attempts']);
    }

    public function test_pop_marks_job_as_reserved(): void
    {
        $id = $this->driver->push('default', 'payload');
        $this->driver->pop('default');

        $row = $this->fetchById($id);

        $this->assertNotNull($row['reserved_at']);
    }

    public function test_pop_does_not_return_reserved_job(): void
    {
        $this->driver->push('default', 'payload');
        $this->driver->pop('default');

        $second = $this->driver->pop('default');

        $this->assertNull($second);
    }

    public function test_pop_does_not_return_future_job(): void
    {
        $this->driver->push('default', 'payload', delay: 3600);

        $this->assertNull($this->driver->pop('default'));
    }

    public function test_pop_does_not_return_failed_job(): void
    {
        $id = $this->driver->push('default', 'payload');
        $this->driver->fail($id, 'error');

        $this->assertNull($this->driver->pop('default'));
    }

    public function test_pop_respects_fifo_order(): void
    {
        $this->driver->push('default', 'first');
        $this->driver->push('default', 'second');
        $this->driver->push('default', 'third');

        $this->assertSame('first',  $this->driver->pop('default')['payload']);
        $this->assertSame('second', $this->driver->pop('default')['payload']);
        $this->assertSame('third',  $this->driver->pop('default')['payload']);
    }

    public function test_pop_isolated_per_queue(): void
    {
        $this->driver->push('high',   'high-job');
        $this->driver->push('default','default-job');

        $this->assertSame('high-job',    $this->driver->pop('high')['payload']);
        $this->assertSame('default-job', $this->driver->pop('default')['payload']);
    }

    // // ─────────────────────────────────────────────────────────────
    // // Delete
    // // ─────────────────────────────────────────────────────────────

    public function test_delete_removes_row(): void
    {
        $id = $this->driver->push('default', 'payload');
        $this->driver->delete($id);

        $this->assertNull($this->fetchById($id));
    }

    public function test_delete_nonexistent_is_noop(): void
    {
        $this->driver->delete('99999');

        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // Release
    // ─────────────────────────────────────────────────────────────

    public function test_release_makes_job_available_again(): void
    {
        // 1. Push un job
        $id = $this->driver->push('default', 'payload');
        $this->assertNotEmpty($id, 'Le push doit retourner un ID.');

        // 2. Pop pour réserver
        $first = $this->driver->pop('default');
        $this->assertNotNull($first, 'Le premier pop doit retourner le job.');
        $this->assertSame('payload', $first['payload']);
        $this->assertSame(1, $first['attempts'], 'Premier pop → attempts = 1');

        // 3. Vérifier que le job est bien réservé
        $rows = $this->fetchByQueue('default');
        $this->assertNotEmpty($rows);
        $this->assertNotNull($rows[0]['reserved_at'], 'Le job doit être réservé.');

        // 4. Release le job
        $this->driver->release($id);

        // 5. Vérifier que reserved_at est null et available_at est maintenant
        $rows = $this->fetchByQueue('default');
        $this->assertNull($rows[0]['reserved_at'], 'Le job doit être libéré.');
        $this->assertLessThanOrEqual(
            date('Y-m-d H:i:s'),
            $rows[0]['available_at'],
            'Le job doit être disponible immédiatement.'
        );

        // 6. Pop à nouveau
        $second = $this->driver->pop('default');
        $this->assertNotNull($second, 'Le second pop doit retourner le job libéré.');
        $this->assertSame(2, $second['attempts'], 'Second pop → attempts = 2');
    }

    public function test_release_with_delay(): void
    {
        // 1. Push un job
        $id = $this->driver->push('default', 'payload');
        $this->assertNotEmpty($id);

        // 2. Pop pour le réserver (vérifier que ça a bien réservé)
        $first = $this->driver->pop('default');
        $this->assertNotNull($first, 'Le premier pop doit réussir.');
        $this->assertSame('payload', $first['payload']);
        $this->assertSame(1, $first['attempts']);

        // 3. Release avec un délai de 1h
        $this->driver->release($id, delay: 3600);

        // 4. Immédiatement : le job ne doit PAS être disponible
        $this->assertNull(
            $this->driver->pop('default'),
            'Le job ne doit pas être disponible avant la fin du délai.'
        );

        // 5. Vérifier que available_at est bien dans le futur
        $updated = $this->fetchById($id);
        $this->assertNotNull($updated);

        $availableAt = strtotime($updated['available_at']);
        $now         = time();

        $this->assertGreaterThan(
            $now + 3500,           // marge de 100s
            $availableAt,
            'available_at doit être au moins 1h dans le futur.'
        );

        $this->assertLessThanOrEqual(
            $now + 3700,           // marge supérieure
            $availableAt,
            'available_at ne doit pas dépasser 1h + une petite marge.'
        );
    }

    // // ─────────────────────────────────────────────────────────────
    // // Fail
    // // ─────────────────────────────────────────────────────────────

    public function test_fail_marks_job_as_failed(): void
    {
        // 1. Push un job
        $id = $this->driver->push('default', 'payload');
        $this->assertNotEmpty($id, 'push() doit retourner un ID non vide.');

        // 2. Marquer le job comme échoué
        $this->driver->fail($id, 'Something went wrong');

        // 3. Vérifier l'état en base
        $row = $this->fetchById($id);
        $this->assertNotNull($row, "Le job #$id doit exister en base.");

        $this->assertNotNull(
            $row['failed_at'],
            'failed_at doit être rempli après fail().'
        );

        $this->assertNotFalse(
            strtotime($row['failed_at']),
            'failed_at doit être une date valide.'
        );

        $this->assertSame(
            'Something went wrong',
            $row['error'],
            'Le message d\'erreur doit être enregistré.'
        );
    }

    public function test_failed_job_is_excluded_from_pop(): void
    {
        $id = $this->driver->push('default', 'payload');
        $this->driver->fail($id, 'error');

        $this->assertNull($this->driver->pop('default'));
    }

    // ─────────────────────────────────────────────────────────────
    // Size
    // ─────────────────────────────────────────────────────────────

    public function test_size_counts_pending_jobs(): void
    {
        $this->driver->push('default', 'a');
        $this->driver->push('default', 'b');
        $this->driver->push('default', 'c');

        $this->assertSame(3, $this->driver->size('default'));
    }

    public function test_size_excludes_reserved_jobs(): void
    {
        $this->driver->push('default', 'a');
        $this->driver->push('default', 'b');

        $this->driver->pop('default');

        $this->assertSame(1, $this->driver->size('default'));
    }

    public function test_size_excludes_failed_jobs(): void
    {
        $a = $this->driver->push('default', 'a');
        $this->driver->push('default', 'b');

        $this->driver->fail($a, 'error');

        $this->assertSame(1, $this->driver->size('default'));
    }

    public function test_size_is_per_queue(): void
    {
        $this->driver->push('high', 'a');
        $this->driver->push('high', 'b');
        $this->driver->push('default', 'c');

        $this->assertSame(2, $this->driver->size('high'));
        $this->assertSame(1, $this->driver->size('default'));
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function createJobsTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE jobs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                queue         TEXT NOT NULL,
                payload       TEXT NOT NULL,
                attempts      INTEGER DEFAULT 0,
                available_at  TEXT NOT NULL,
                reserved_at   TEXT NULL,
                failed_at     TEXT NULL,
                error         TEXT NULL,
                created_at    TEXT NOT NULL
            )
        ');
    }

    private function fetchById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ?: null;
    }

    private function fetchByQueue(string $queue): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE queue = ? ORDER BY id');
        $stmt->execute([$queue]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function parseTimestamp(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        $ts = strtotime((string) $value);

        if ($ts === false) {
            throw new \RuntimeException(
                "Format de date non reconnu : " . var_export($value, true)
            );
        }

        return $ts;
    }
}