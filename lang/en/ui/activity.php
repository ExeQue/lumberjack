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
        'subjects' => 'Subjects',
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
    'filters' => [
        'label' => 'Filter by actor or subject',
        'actor' => 'Actor',
        'subject' => 'Subject',
        'actor_placeholder' => 'Any actor',
        'subject_placeholder' => 'Any subject',
        'actor_count' => ':count actors',
        'subject_count' => ':count subjects',
        'search' => 'Search by type or key, e.g. user:17',
        'no_matches' => 'Nothing matches in the period',
        // Button
        'actor_any' => 'Any actor',
        // Button
        'subject_any' => 'Any subject',
    ],
    'filter_actor' => 'Filter by :reference too',
    'filter_subject' => 'Filter by :reference too',
    // Button
    'forget' => 'Forget actor',
    'forget_question' => 'Delete all activity of :reference in this project? Their hourly counts stay.',
    'rename_group' => 'Name for the group :group; leave empty to forget the name',
    'rename_activity' => 'Name for :activity; leave empty to forget the name',
    'payload_truncated' => 'Payload too large, not kept',
    'pages' => 'Pages',
    // Button
    'newer' => 'Newer',
    // Button
    'older' => 'Older',
];
