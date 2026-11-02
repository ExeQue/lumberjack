<?php

declare(strict_types=1);

return [
    'title' => 'Trace',
    'description' => 'Everything that happened under this trace, on one time axis.',
    'request' => 'Request',
    'job' => 'Job',
    'timeline' => 'Timeline',
    'timeline_hint' => 'Bars show how long each step took, placed where it started.',
    'empty' => 'Nothing was recorded on this trace besides the request itself.',
    'at' => 'At',
    'what' => 'What',
    'took' => 'Took',
    'summary' => [
        'queries' => '{1} 1 query|[2,*] :count queries',
        'http' => '{1} 1 outgoing call|[2,*] :count outgoing calls',
        'cache' => '{1} 1 cache operation|[2,*] :count cache operations',
        'cache_misses' => ':count missed',
        'mail' => '{1} 1 mail|[2,*] :count mails',
        'notification' => '{1} 1 notification|[2,*] :count notifications',
        'redis' => '{1} 1 Redis command|[2,*] :count Redis commands',
        'log' => '{1} 1 log line|[2,*] :count log lines',
        'exception' => '{1} 1 exception|[2,*] :count exceptions',
    ],
    'kinds' => [
        'query' => 'Query',
        'cache' => 'Cache',
        'http' => 'HTTP',
        'mail' => 'Mail',
        'notification' => 'Notification',
        'redis' => 'Redis',
        'log' => 'Log',
        'exception' => 'Exception',
    ],
    'recipients' => '{1} to 1 recipient|[2,*] to :count recipients',
    // Link
    'open' => 'Open trace',
];
