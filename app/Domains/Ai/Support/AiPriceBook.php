<?php

declare(strict_types=1);

namespace App\Domains\Ai\Support;

use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\LruCache;
use Carbon\CarbonInterface;

/**
 * Every price that can apply to a project's AI calls, loaded once, and which one does.
 *
 * The narrowest price wins: one key, then a key owner's keys, then the project, then the
 * whole team. Between prices as narrow, an exact model name beats a glob, one for a
 * provider beats one for any, and a longer glob a shorter one. Of those, the one valid
 * latest at the time asked for. A price not valid yet at that time does not apply, so a
 * broader one can.
 */
final class AiPriceBook
{
    /**
     * The prices that can apply to one provider, model, key and owner, in the order they win,
     * by those four. Bounded: a project can have thousands of keys.
     *
     * @var LruCache<list<AiPrice>>
     */
    private LruCache $candidates;

    /**
     * @param  list<AiPrice>  $prices  in the order they win (see sorted)
     */
    private function __construct(private readonly array $prices)
    {
        $this->candidates = new LruCache(1000);
    }

    public static function for(Project $project): self
    {
        return new self(self::sorted(array_values(AiPrice::query()->whereAppliesTo($project)->get()->all())));
    }

    /**
     * @param  list<AiPrice>  $prices
     */
    public static function of(array $prices): self
    {
        return new self(self::sorted($prices));
    }

    /**
     * @return list<AiPrice>
     */
    public function prices(): array
    {
        return $this->prices;
    }

    /**
     * Every moment a price starts, earliest first: between two of them, the same price applies.
     *
     * @return list<string> as Y-m-d H:i:s
     */
    public function boundaries(): array
    {
        $starts = array_unique(array_map(fn (AiPrice $price): string => $price->valid_from->format('Y-m-d H:i:s'), $this->prices));
        sort($starts);

        return $starts;
    }

    public function resolve(?string $provider, string $model, ?string $fingerprint, ?string $ownerType, ?string $ownerId, CarbonInterface $at): ?AiPrice
    {
        $candidates = $this->candidates->remember(
            implode("\0", [$provider, $model, $fingerprint, $ownerType, $ownerId]),
            fn (): array => array_values(array_filter($this->prices, fn (AiPrice $price): bool => $price->matchesModel($provider, $model) && match ($price->scope) {
                AiPriceScope::Key => $fingerprint !== null && $fingerprint !== '' && $price->scope_id === $fingerprint,
                AiPriceScope::Owner => $ownerType !== null && $ownerType !== '' && $price->scope_type === $ownerType && $price->scope_id === $ownerId,
                AiPriceScope::Project => true,
            })),
        );

        foreach ($candidates as $price) {
            if ($price->valid_from->lessThanOrEqualTo($at)) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @param  list<AiPrice>  $prices
     * @return list<AiPrice>
     */
    private static function sorted(array $prices): array
    {
        usort($prices, fn (AiPrice $a, AiPrice $b): int => [$a->rank(), $a->isGlob(), $a->provider === null, -strlen($a->model_pattern), -$a->valid_from->getTimestamp()]
            <=> [$b->rank(), $b->isGlob(), $b->provider === null, -strlen($b->model_pattern), -$b->valid_from->getTimestamp()]);

        return $prices;
    }
}
