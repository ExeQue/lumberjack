<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Enums\Umami;

use App\Domains\DataSources\Enums\DataSourceShape;

/**
 * The data sources every Umami connection offers screens, besides one per custom event.
 * The value is the source's key.
 */
enum UmamiSource: string
{
    case Visitors = 'visitors';
    case Visits = 'visits';
    case Pageviews = 'pageviews';
    case BounceRate = 'bounce_rate';
    case VisitDuration = 'visit_duration';
    case Active = 'active';
    case VisitorsSeries = 'visitors.series';
    case PageviewsSeries = 'pageviews.series';
    case Pages = 'pages';
    case EntryPages = 'entry_pages';
    case Referrers = 'referrers';
    case Channels = 'channels';
    case Countries = 'countries';
    case Browsers = 'browsers';
    case Os = 'os';
    case Devices = 'devices';
    case Languages = 'languages';
    case Events = 'events';
    case UtmSources = 'utm_sources';
    case UtmCampaigns = 'utm_campaigns';

    /** The keys of a custom event's sources begin so. */
    public const string EVENT_PREFIX = 'event.';

    /** The keys of a UTM campaign's sources begin so. */
    public const string CAMPAIGN_PREFIX = 'campaign.';

    public function shape(): DataSourceShape
    {
        return match ($this) {
            self::Visitors, self::Visits, self::Pageviews, self::BounceRate, self::VisitDuration, self::Active => DataSourceShape::Number,
            self::VisitorsSeries, self::PageviewsSeries => DataSourceShape::Series,
            default => DataSourceShape::List,
        };
    }

    /** The breakdown a list reads from /metrics. */
    public function metricType(): ?string
    {
        return match ($this) {
            self::Pages => 'path',
            self::EntryPages => 'entry',
            self::Referrers => 'referrer',
            self::Channels => 'channel',
            self::Countries => 'country',
            self::Browsers => 'browser',
            self::Os => 'os',
            self::Devices => 'device',
            self::Languages => 'language',
            self::Events => 'event',
            self::UtmSources => 'utmSource',
            self::UtmCampaigns => 'utmCampaign',
            default => null,
        };
    }

    public function label(): string
    {
        return __('connectors.umami.sources.'.str_replace('.', '_', $this->value));
    }
}
