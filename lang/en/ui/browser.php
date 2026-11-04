<?php

declare(strict_types=1);

return [
    'title' => 'Browser',
    'description' => '{0} No page views in this period.|{1} 1 page view in this period, measured by the browser script.|[2,*] :count page views in this period, measured by the browser script.',
    'device' => [
        'label' => 'Device',
        'all' => 'All',
        'mobile' => 'Mobile',
        'tablet' => 'Tablet',
        'desktop' => 'Desktop',
    ],
    'cards' => [
        'views' => 'Page views',
        'lcp' => 'LCP p75',
        'inp' => 'INP p75',
        'cls' => 'CLS p75',
        'good' => ':percent good',
    ],
    'devices' => [
        'title' => 'LCP by device',
        'description' => 'p75 per device class. Page views from an older browser script have none and count only under All.',
        'views' => '{0} no page views|{1} 1 page view|[2,*] :count page views',
    ],
    'charts' => [
        'lcp' => 'Largest Contentful Paint, p75',
        'inp' => 'Interaction to Next Paint, p75',
        'cls' => 'Cumulative Layout Shift, p75',
        'summary' => ':vital p75 per bucket over the period; the highest is :max.',
    ],
    'pages' => [
        'title' => 'Pages',
        'description' => 'p75 per browser page. Green is good, amber needs improvement, red is poor.',
        'empty' => 'No page views match these filters.',
        'page' => 'Page',
        'views' => 'Page views',
        'lcp' => 'LCP',
        'lcp_help' => 'Largest Contentful Paint: good within 2.5 s, poor above 4 s.',
        'inp' => 'INP',
        'inp_help' => 'Interaction to Next Paint: good within 200 ms, poor above 500 ms.',
        'cls' => 'CLS',
        'cls_help' => 'Cumulative Layout Shift: good within 0.1, poor above 0.25.',
        'ttfb' => 'TTFB',
        'ttfb_help' => 'Time to First Byte: how long the server took to start answering.',
        'fcp' => 'FCP',
        'fcp_help' => 'First Contentful Paint: when the first text or image was painted.',
        'sort' => [
            'label' => 'Sort pages',
            'views' => 'Most viewed',
            'lcp' => 'Slowest LCP',
        ],
    ],
    'errors' => [
        'title' => 'Browser errors',
        'description' => '{0} No browser errors in this period.|{1} 1 browser error in this period, on every device.|[2,*] :count browser errors in this period, on every device.',
        'all' => 'All issues',
        'occurrences' => '{1} 1 occurrence|[2,*] :count occurrences',
    ],
    'empty' => [
        'title' => 'No Web Vitals yet',
        'body' => 'Add the browser script to your pages and it measures LCP, INP and CLS on every page view.',
        // Link
        'link' => 'Set up the browser script',
    ],
];
