<?php

namespace Clicalmani\Queue\Drivers;

use Clicalmani\Queue\Contracts\QueueDriver;
use Clicalmani\Core\Support\Facades\DB;
use Clicalmani\Database\Concerns\IsolationLevel;

final class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(
        private string $table = 'jobs',
    ) {}

    public function push(string $queue, string $payload, int $delay = 0): string
    {
        return (string) DB::table($this->table)->insert([
            'queue'        => $queue,
            'payload'      => $payload,
            'attempts'     => 0,
            'available_at' => date('Y-m-d H:i:s', time() + $delay),
            'created_at'   => date('Y-m-d H:i:s'),
        ])->getId();
    }

    public function pop(string $queue): ?array
    {
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::isolateTransaction(IsolationLevel::SERIALIZABLE);
            } catch (\Throwable) {
                // ignore : isolation déjà par défaut
            }
        }
        
        // Transaction pour éviter la double-consommation
        return DB::transaction(function () use ($queue) {
            $row = DB::table($this->table)
                ->where('queue = ?', [$queue])
                ->where('reserved_at IS NULL')
                ->where('failed_at IS NULL')
                ->where('available_at <= ?', [date('Y-m-d H:i:s')])
                ->orderBy('id')
                ->first();

            if (! $row) {
                return null;
            }
            
            $affected = DB::table($this->table)
                ->where('id = ?', [$row->id])
                ->update([
                    'reserved_at' => date('Y-m-d H:i:s'),
                    'attempts'    => $row->attempts + 1,
                ]);

            if ($affected === 0) {
                return null;   // un autre processus l'a réservé entre-temps
            }

            return [
                'id'       => (string) $row->id,
                'payload'  => $row->payload,
                'attempts' => $row->attempts + 1,
            ];
        });
    }

    public function delete(string $id): void
    {
        DB::table($this->table)->where('id = ?', [$id])->delete();
    }

    public function release(string $id, int $delay = 0): void
    {
        DB::table($this->table)->where('id = ?', [$id])->update([
            'reserved_at'  => null,
            'available_at' => date('Y-m-d H:i:s', time() + $delay),
        ]);
    }

    public function fail(string $id, string $reason): void
    {
        DB::table($this->table)->where('id = ?', [$id])->update([
            'failed_at' => date('Y-m-d H:i:s'),
            'error'     => $reason,
        ]);
    }

    public function size(string $queue): int
    {
        return (int) DB::table($this->table)
            ->where('queue = ?', [$queue])
            ->where('reserved_at IS NULL')
            ->where('failed_at IS NULL')
            ->count();
    }
}