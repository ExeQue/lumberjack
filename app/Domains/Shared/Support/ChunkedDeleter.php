<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes matching rows in small batches so a big cleanup never holds a long lock.
 */
class ChunkedDeleter
{
    public const int CHUNK = 5000;

    /**
     * @param  Closure(Builder): mixed  $constrain
     */
    public function delete(string $table, Closure $constrain, int $chunk = self::CHUNK): int
    {
        $total = 0;

        do {
            $query = DB::table($table);
            $constrain($query);
            $deleted = $query->limit($chunk)->delete();
            $total += $deleted;
        } while ($deleted === $chunk);

        return $total;
    }
}
