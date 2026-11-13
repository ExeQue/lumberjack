<?php

declare(strict_types=1);

return [
    'title' => 'Activity',
    'description' => '{0} No activity in the period.|{1} 1 kind of activity in the period.|[2,*] :count kinds of activity in the period.',
    'empty_title' => 'No activity yet',
    'empty_body' => 'Record what happens in your app with Lumberjack::activity(\'order.placed\')->send(), optionally with a group, an actor, a subject and a payload.',
    'trend_label' => ':count in the period',
    'columns' => [
        'event' => 'Activity',
        'count' => 'Count',
        'trend' => 'Trend',
        'last_seen' => 'Last seen',
        'time' => 'Time',
        'actor' => 'Actor',
        'subject' => 'Subject',
        'payload' => 'Payload',
        'release' => 'Release',
    ],
    'occurrences' => 'Occurrences',
    'occurrences_description' => '{0} No occurrences in the period.|{1} 1 occurrence in the period.|[2,*] :count occurrences in the period.',
    'no_occurrences' => 'No occurrences match',
    'sort' => [
        'label' => 'Sort by',
        'time' => 'Newest first',
        'actor' => 'Actor',
    ],
    'filter_actor' => 'Show only :reference',
    'filter_subject' => 'Show only :reference',
    // Button
    'clear_actor' => 'Actor: :reference ×',
    // Button
    'clear_subject' => 'Subject: :reference ×',
    // Button
    'forget' => 'Forget actor',
    'forget_question' => 'Delete all activity of :reference in this project? Their hourly counts stay.',
    'payload_truncated' => 'Payload too large, not kept',
    'pages' => 'Pages',
    // Button
    'newer' => 'Newer',
    // Button
    'older' => 'Older',
];
