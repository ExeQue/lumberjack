<?php

declare(strict_types=1);

use App\Domains\Performance\Support\SqlNormalizer;

covers(SqlNormalizer::class);

test('queries that differ only in values normalize to the same text', function (string $a, string $b) {
    $normalizer = new SqlNormalizer();

    expect($normalizer->normalize($a))->toBe($normalizer->normalize($b));
})->with([
    'in lists of different length' => ['select * from t where id in (?, ?)', 'select * from t where id in (?,?,?,?)'],
    'inlined literals' => ["select * from t where name = 'a' and id = 5", "select * from t where name = 'bb' and id = 77"],
    'whitespace' => ["select *\n  from t", 'select * from t'],
]);
