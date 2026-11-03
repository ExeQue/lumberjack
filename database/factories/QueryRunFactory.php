<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Support\SqlNormalizer;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryRun>
 */
class QueryRunFactory extends Factory
{
    public function definition(): array
    {
        $sql = 'select * from `users` where `id` = ?';

        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'connection' => 'mysql',
            'sql' => $sql,
            'sql_hash' => hash('sha256', $sql),
            'duration_us' => fake()->numberBetween(100, 50_000),
            'occurred_at' => now(),
        ];
    }

    /** This statement, hashed as ingest hashes it, run from this file and line. */
    public function forSql(string $sql, ?string $location = null): static
    {
        $normalizer = app(SqlNormalizer::class);

        return $this->state(fn () => ['sql' => $sql, 'sql_hash' => $normalizer->hash($normalizer->normalize($sql)), 'location' => $location]);
    }

    /** Part of this request's or job's trace. */
    public function inTrace(string $traceId): static
    {
        return $this->state(fn () => ['trace_id' => $traceId]);
    }
}
