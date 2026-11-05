<?php

declare(strict_types=1);

return [
    /*
     * Browser push notifications: the title, then the line under it.
     */
    'mail' => [
        // Button
        'open' => 'Open in Lumberjack',
    ],

    'push' => [
        'mentioned' => ':author tagged you on :title',
        'new_issue' => 'New error: :title',
        'regression' => 'Regression: :title',
        'escalation' => 'Escalating: :title',
        'agent_offline' => '{1} An agent went offline in :project|[2,*] :count agents went offline in :project',
        'release' => 'New release in :project',
        'release_body' => ':release was first seen just now.',
    ],
];
