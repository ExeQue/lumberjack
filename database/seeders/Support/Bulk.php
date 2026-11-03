<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes many rows of one model fast: each row is made by its factory, with the
 * factory's states and the attributes given, and stored as the model would store
 * it (enums as values, arrays as JSON, dates in its format), but inserted in chunks
 * of plain rows instead of one save per model. Nothing fires: no events, no
 * observers, no timestamps the row does not carry itself.
 */
final class Bulk
{
    /** @var array<string, list<array<string, mixed>>> rows waiting per table */
    private array $pending = [];

    private int $written = 0;

    public static float $spent = 0;

    public function __construct(private readonly int $chunk = 5000)
    {
    }

    /**
     * @template TModel of Model
     *
     * @param  Factory<TModel>  $factory
     * @param  array<string, mixed>  $attributes
     */
    public function add(Factory $factory, array $attributes = []): void
    {
        $model = $factory->makeOne($attributes);
        $this->push($model->getTable(), $model->getAttributes());
    }

    /**
     * A row as the factory makes it and the model stores it, to copy for a series of
     * rows that differ only in a few plain columns (a count per minute).
     *
     * @template TModel of Model
     *
     * @param  Factory<TModel>  $factory
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function row(Factory $factory, array $attributes = []): array
    {
        return $factory->makeOne($attributes)->getAttributes();
    }

    /**
     * Queues a row already in stored form, such as a copy of row() with its columns changed.
     *
     * @param  array<string, mixed>  $row
     */
    public function push(string $table, array $row): void
    {
        $this->pending[$table][] = $row;

        if (count($this->pending[$table]) >= $this->chunk) {
            $this->flushTable($table);
        }
    }

    /**
     * Inserts what is still waiting; returns how many rows this writer stored in all.
     */
    public function flush(): int
    {
        foreach (array_keys($this->pending) as $table) {
            $this->flushTable($table);
        }

        return $this->written;
    }

    private function flushTable(string $table): void
    {
        $rows = $this->pending[$table] ?? [];
        unset($this->pending[$table]);

        if ($rows === []) {
            return;
        }

        // One statement per set of columns: states may fill different ones, and a
        // column left out keeps its database default instead of turning null.
        $groups = [];

        foreach ($rows as $row) {
            ksort($row);
            $groups[implode(',', array_keys($row))][] = $row;
        }

        $t = microtime(true);
        foreach ($groups as $group) {
            // As many rows per statement as Postgres takes bound values (65535).
            foreach (array_chunk($group, max(1, intdiv(60_000, max(1, count($group[0]))))) as $rows) {
                DB::table($table)->insert($rows);
            }
        }
        self::$spent += microtime(true) - $t;

        $this->written += count($rows);
    }
}
