<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Actions\Umami;

use App\Domains\Connectors\Exceptions\Umami\UmamiRequestFailedException;
use App\Domains\Connectors\Queries\Umami\UmamiDashboardQuery;
use App\Domains\Connectors\Support\Umami\UmamiClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use Carbon\CarbonImmutable;

/**
 * Marks a moment on an Umami website, such as a campaign's launch, as an annotation in
 * Umami itself, so it shows on Umami's charts as well as on the Umami page.
 */
class CreateUmamiAnnotationAction
{
    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @param  CarbonImmutable  $at  the day, or the moment, in the connection's time zone
     *
     * @throws UmamiRequestFailedException
     */
    public function execute(DataSourceConnection $connection, CarbonImmutable $at, bool $allDay, string $note): void
    {
        $note = trim($note);

        if (! $connection->isUmami() || $note === '') {
            return;
        }

        $this->client->createAnnotation($connection->umamiCredentials(), (string) $connection->website_id, $at, $allDay, $note);
        UmamiDashboardQuery::forgetAnnotations($connection);
    }
}
