<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Enums\UmamiSource;
use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Support\UmamiClient;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSourceConnection;
use Illuminate\Support\Str;

/**
 * The sources an Umami website offers screens, in the form an app's list takes: the fixed
 * ones, and a count and a series for each custom event seen in the last 30 days, and for
 * each UTM campaign seen then or given a name. Asking
 * for the events is also what proves the credentials and the website still work.
 */
class BuildUmamiCatalogAction
{
    /** The most custom events offered, the most frequent first. */
    public const int MAX_EVENTS = 50;

    /** The most campaigns seen lately offered, the most visited first; named ones come besides. */
    public const int MAX_CAMPAIGNS = 20;

    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @return array{version: int, complete: bool, sources: list<array{key: string, label: string, shape: string, group: list<string>, filter?: string}>}
     *
     * @throws UmamiRequestFailedException
     */
    public function execute(DataSourceConnection $connection): array
    {
        $credentials = $connection->umamiCredentials();
        $website = $connection->umamiWebsiteId();
        // Filed under Umami and the website where they are shown, so only what lies below.
        $group = [];
        $sources = array_map(fn (UmamiSource $source): array => ['key' => $source->value, 'label' => $source->label(), 'shape' => $source->shape()->value, 'group' => $group], UmamiSource::cases());

        foreach ($this->client->metrics($credentials, $website, 'event', now()->subDays(30), now(), self::MAX_EVENTS) as $event) {
            if ($event['x'] !== '') {
                $sources = [...$sources, ...self::pair(UmamiSource::EVENT_PREFIX, $event['x'], $event['x'], [...$group, __('connectors.umami.sources.event_group')])];
            }
        }

        $labels = $connection->utmLabels()->where('dimension', UtmDimension::Campaign)->pluck('label', 'value')->all();
        $seen = array_column($this->client->metrics($credentials, $website, 'utmCampaign', now()->subDays(30), now(), self::MAX_CAMPAIGNS), 'x');

        // Those seen lately, and those given a name, which stay on a screen when quiet.
        foreach (array_unique([...array_keys($labels), ...array_filter($seen, fn (string $c): bool => $c !== '')]) as $campaign) {
            $campaign = (string) $campaign;
            $sources = [...$sources, ...self::pair(UmamiSource::CAMPAIGN_PREFIX, $campaign, $labels[$campaign] ?? $campaign, [...$group, __('connectors.umami.sources.campaign_group')])];
        }

        return ['version' => 1, 'complete' => true, 'sources' => $sources];
    }

    /**
     * A count and a series narrowed to one value.
     *
     * @param  list<string>  $group
     * @return list<array{key: string, label: string, shape: string, group: list<string>, filter: string}>
     */
    private static function pair(string $prefix, string $value, string $label, array $group): array
    {
        $key = self::key($prefix, $value);

        return [
            ['key' => $key, 'label' => $label, 'shape' => DataSourceShape::Number->value, 'group' => $group, 'filter' => $value],
            ['key' => $key.'.series', 'label' => __('connectors.umami.sources.over_time', ['name' => $label]), 'shape' => DataSourceShape::Series->value, 'group' => $group, 'filter' => $value],
        ];
    }

    /**
     * A key for a source narrowed to a value, readable where it can be and unique by its
     * hash, as event and campaign names may hold any character and a key may not.
     */
    public static function key(string $prefix, string $value): string
    {
        $slug = Str::limit(Str::slug(str_replace(':', '-', $value)), 60, '');

        return $prefix.($slug === '' ? '' : $slug.'-').substr(hash('sha256', $value), 0, 8);
    }
}
