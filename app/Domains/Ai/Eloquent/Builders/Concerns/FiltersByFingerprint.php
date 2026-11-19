<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders\Concerns;

/**
 * Rows of one AI key, or of any of several, by fingerprint.
 */
trait FiltersByFingerprint
{
    /** @param  string|list<string>  $fingerprints */
    public function whereFingerprint(string|array $fingerprints): self
    {
        return $this->whereIn('fingerprint', (array) $fingerprints);
    }

    /** @param  string|list<string>  $fingerprints */
    public function orWhereFingerprint(string|array $fingerprints): self
    {
        return $this->orWhereIn('fingerprint', (array) $fingerprints);
    }
}
