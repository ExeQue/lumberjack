<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Actions\Umami;

use App\Domains\Connectors\Exceptions\Umami\UmamiRequestFailedException;
use App\Domains\Connectors\Queries\Umami\UmamiDashboardQuery;
use App\Domains\Connectors\Support\Umami\UmamiClient;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * Removes an annotation from the Umami website, in Umami too.
 */
class DeleteUmamiAnnotationAction
{
    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @throws UmamiRequestFailedException
     */
    public function execute(DataSourceConnection $connection, string $annotationId): void
    {
        if (! $connection->isUmami()) {
            return;
        }

        $this->client->deleteAnnotation($connection->umamiCredentials(), (string) $connection->website_id, $annotationId);
        UmamiDashboardQuery::forgetAnnotations($connection);
    }
}
