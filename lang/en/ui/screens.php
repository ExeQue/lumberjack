<?php

declare(strict_types=1);

return [
    'title' => 'Screens',
    'description' => 'Status screens for a TV or a monitor on the wall: live, readable across the room, no interaction needed.',
    // Button.
    'new' => 'New screen',
    // Button.
    'create' => 'Create screen',
    'create_hint' => 'Start from a ready layout or an empty grid; the designer opens next and everything can be changed there.',
    'template' => 'Start from',
    'templates' => [
        'blank' => ['label' => 'Empty', 'description' => 'A 4 x 4 grid to fill yourself.'],
        'overview' => ['label' => 'Overview', 'description' => 'Traffic, errors, latency, issues, health, queues and hosts at a glance.'],
        'on_call' => ['label' => 'On call', 'description' => 'What needs attention: errors, newest issues, failing checks and alerts.'],
        'infrastructure' => ['label' => 'Infrastructure', 'description' => 'CPU, memory and disk per host, agents and every health check.'],
        'background' => ['label' => 'Jobs and schedules', 'description' => 'Failed jobs, queue sizes and how the scheduled tasks ran.'],
    ],
    // Name of a duplicated screen, at most 100 characters.
    'copy_of' => ':name (copy)',

    'empty' => [
        'title' => 'No screens yet',
        'body' => 'A screen shows a grid of widgets (numbers, gauges, charts, lists and health checks) on a TV. Make one with New screen.',
    ],

    'table' => [
        'name' => 'Name',
        'grid' => 'Grid',
        'widgets' => 'Widgets',
        'theme' => 'Theme',
        'updated' => 'Changed',
        'actions' => 'Actions',
    ],

    // Buttons on the list of screens.
    'actions' => [
        'open' => 'Open kiosk',
        'edit' => 'Design',
        'rename' => 'Rename',
        'duplicate' => 'Duplicate',
        'delete' => 'Delete',
        'delete_question' => 'Delete the screen :name? TVs showing it go blank.',
        'save' => 'Save',
    ],

    'theme' => [
        'dark' => 'Dark',
        'light' => 'Light',
        'schedule' => 'Light by day, dark at night',
    ],

    'periods' => [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ],

    // Widget types, as the palette and the inspector name them.
    'kinds' => [
        'stat' => 'Stat',
        'gauge' => 'Gauge',
        'chart' => 'Chart',
        'list' => 'List',
        'health' => 'Health',
        'clock' => 'Clock and text',
        'empty' => 'Empty cell',
    ],

    // What each type is for, under its name in the palette.
    'hints' => [
        'stat' => 'One number: requests, errors, jobs, queues, agents',
        'gauge' => 'One value against its limits',
        'chart' => 'Any metric over the period',
        'list' => 'Issues, alerts, jobs, tasks, routes, queues, hosts',
        'health' => 'All checks, or pick one for its timeline',
        'clock' => 'Time, a title or a note',
        'empty' => 'Blank space; not shown on the TV',
    ],

    'palette' => [
        'title' => 'Add a widget',
        'search' => 'Find a widget',
        'data' => 'Data',
        'layout' => 'Layout',
        'none' => 'No widget matches.',
        'hint' => 'Drag onto the grid, or click to add at the end.',
    ],

    'designer' => [
        'empty' => [
            'title' => 'An empty screen',
            'body' => 'Drag a widget from the left onto the grid, click one to add it, or fill the screen from a ready layout and change it from there.',
            'use' => 'Use :template',
        ],
        'title' => 'Design :name',
        'grid' => 'Screen',
        'count' => '{0} No widgets|{1} 1 widget|[2,*] :count widgets',
        'cols' => 'Columns',
        'rows' => 'Rows',
        'theme' => 'Theme',
        'light_from' => 'Light from',
        'light_to' => 'until',
        'now_theme' => 'now :theme',
        'preview' => 'Preview data',
        // Toggle buttons.
        'live_data' => 'Live data',
        'incident' => 'Show an incident',
        'saving' => 'Saving...',
        'saved' => 'Saved',
        'saved_live' => '{0} Saved, on no screen right now|{1} Saved, live on 1 screen|[2,*] Saved, live on :count screens',
        'error' => 'Not saved',
        'not_saved' => 'Not saved: :reason',
        'too_many' => 'A screen holds at most 48 widgets.',
        // Button, opens the full screen view in a new tab.
        'open_kiosk' => 'Open kiosk',
        'overflow' => '{1} 1 widget does not fit on the :cols x :rows screen and does not show on the TV. Add columns or rows, or make widgets smaller.|[2,*] :count widgets do not fit on the :cols x :rows screen and do not show on the TV. Add columns or rows, or make widgets smaller.',
        'drop_end' => 'Drop here to add at the end',
        'help' => 'Drag a widget to move it, its corner to resize it. With a widget focused: arrows move it, Shift and arrows resize it, Delete removes it, Escape shows the screen settings.',
        'tile' => ':kind: :title, :w by :h',
        'tile_untitled' => ':kind, :w by :h',
    ],

    'inspector' => [
        'label' => 'Settings',
        // Button.
        'remove' => 'Remove',
        'title' => 'Title',
        'shows' => 'Shows',
        'rotate' => 'Rotate through',
        'rotate_on' => 'Shows :count values in turn; timing under Rotation.',
        'rotate_off' => 'Pick more to rotate between them on the same tile.',
        'limits_above' => 'Turns amber and red at or above the limits; empty uses the defaults shown.',
        'limits_below' => 'Turns amber and red at or below the limits; empty uses the defaults shown.',
        'ratio_hint' => 'Shares go from 0 to 1: 0.05 is 5%.',
        'ms_hint' => 'In milliseconds.',
        'size' => 'Size',
        'auto' => 'Automatic',
        'screen_default' => 'Screen default',
        'screen_timezone' => 'The screen\'s time zone',
        'all_projects' => 'All in the team',
        'all_environments' => 'All environments',
        'no_checks' => 'The team has no health checks yet.',
        'checks_hint' => 'None picked: every check of the projects, new ones included. One picked: that check alone, with its uptime and timeline.',
        'empty_hint' => 'Keeps this space empty on the TV. Resize it to make a gap of any size.',
    ],

    'settings' => [
        'title' => 'Screen settings',
        'hint' => 'What every widget shows unless it picks its own. Click a widget to change it.',
        'name' => 'Name',
        'projects' => 'Projects',
        'projects_hint' => 'None ticked: every project of the team, new ones included.',
        'timezone' => 'Time zone',
        'timezone_hint' => 'For the clock and for switching between light and dark.',
    ],

    'sections' => [
        'value' => 'Value',
        'scale' => 'Scale',
        'chart' => 'Chart',
        'rows' => 'Rows',
        'checks' => 'Checks',
        'clock' => 'Clock',
        'rotation' => 'Rotation',
        'scope' => 'Scope',
        'display' => 'Display',
    ],

    // Widget options: the label of each field, and of its choices.
    'fields' => [
        'amber' => ['label' => 'Amber at'],
        'red' => ['label' => 'Red at'],
        'low_is_bad' => ['label' => 'Low values are bad'],
        'min' => ['label' => 'Minimum'],
        'max' => ['label' => 'Maximum'],
        'show_limits' => ['label' => 'Mark the amber limit on the arc'],
        'compare' => ['label' => 'Compare with', 'options' => ['none' => 'Nothing', 'previous' => 'Previous period', 'yesterday' => 'Same time yesterday', 'last_week' => 'Same time last week']],
        'decimals' => ['label' => 'Decimals', 'options' => ['auto' => 'Automatic', '0' => '0', '1' => '1', '2' => '2']],
        'sparkline' => ['label' => 'Sparkline under the number'],
        'prefix' => ['label' => 'Prefix', 'placeholder' => 'e.g. ~'],
        'suffix' => ['label' => 'Suffix', 'placeholder' => 'e.g. /min'],
        'style' => ['label' => 'Style', 'options' => ['bars' => 'Bars', 'area' => 'Area', 'line' => 'Line']],
        'split_by' => ['label' => 'Split by', 'options' => ['none' => 'Nothing', 'route' => 'Route', 'queue' => 'Queue', 'host' => 'Host', 'environment' => 'Environment', 'release' => 'Release', 'project' => 'Project']],
        'top' => ['label' => 'Series', 'options' => ['5' => 'Top 5', '10' => 'Top 10', '20' => 'Top 20']],
        'overlay_previous' => ['label' => 'Overlay the previous period'],
        'limit_line' => ['label' => 'Draw the red limit'],
        'y_zero' => ['label' => 'Y axis starts at zero'],
        'legend' => ['label' => 'Legend'],
        'axis' => ['label' => 'Time axis'],
        'y_axis' => ['label' => 'Value axis'],
        'grid' => ['label' => 'Grid lines'],
        'limit' => ['label' => 'Rows', 'options' => ['auto' => 'Fit to tile', '3' => '3', '5' => '5', '10' => '10', '20' => '20']],
        'sort' => ['label' => 'Sort', 'options' => ['newest' => 'Newest first', 'most_frequent' => 'Most frequent']],
        'only_problems' => ['label' => 'Only what needs attention'],
        'show_age' => ['label' => 'Show age'],
        'show_count' => ['label' => 'Show count'],
        'show_project' => ['label' => 'Show project'],
        'wrap' => ['label' => 'Wrap long text'],
        'view' => ['label' => 'Show as', 'options' => ['auto' => 'Automatic', 'summary' => 'Summary', 'list' => 'List', 'cards' => 'Cards']],
        'checks' => ['label' => 'Checks'],
        'show_uptime' => ['label' => 'Show uptime'],
        'show_timeline' => ['label' => 'Show the timeline'],
        'show_message' => ['label' => 'Show the last message'],
        'stale_as' => ['label' => 'Stale counts as', 'options' => ['stale' => 'Its own state', 'degraded' => 'Degraded', 'ok' => 'Ok']],
        'mode' => ['label' => 'Shows', 'options' => ['clock' => 'Clock', 'text' => 'Text only', 'both' => 'Clock and text']],
        'format' => ['label' => 'Format', 'options' => ['24' => '24 hour', '12' => '12 hour']],
        'timezone' => ['label' => 'Time zone'],
        'seconds' => ['label' => 'Seconds'],
        'date' => ['label' => 'Date'],
        'text' => ['label' => 'Text', 'placeholder' => 'A note for the room'],
        'rotate_every' => ['label' => 'Switch every', 'options' => ['5' => '5 seconds', '10' => '10 seconds', '20' => '20 seconds', '30' => '30 seconds', '60' => '1 minute']],
        'transition' => ['label' => 'Transition', 'options' => ['fade' => 'Fade', 'slide' => 'Slide', 'none' => 'None']],
        'hold_problem' => ['label' => 'Stay on a value that needs attention'],
        'dots' => ['label' => 'Show which one is showing'],
        'projects' => ['label' => 'Projects'],
        'environment' => ['label' => 'Environment'],
        'period' => ['label' => 'Period'],
        'release' => ['label' => 'Release', 'options' => ['all' => 'All', 'current' => 'Current only', 'previous' => 'Previous']],
        'filter' => ['label' => 'Filter', 'placeholder' => 'route, queue, job or host; * matches anything'],
        'show_title' => ['label' => 'Show title'],
        'status_colors' => ['label' => 'Color the tile by status'],
        'text_size' => ['label' => 'Text size', 'options' => ['auto' => 'Fit to tile', 's' => 'Small', 'm' => 'Medium', 'l' => 'Large', 'xl' => 'Huge']],
        'align' => ['label' => 'Align', 'options' => ['left' => 'Left', 'center' => 'Center']],
        'valign' => ['label' => 'Vertically', 'options' => ['top' => 'Top', 'middle' => 'Middle']],
        'refresh' => ['label' => 'Update', 'options' => ['live' => 'Live', '1m' => 'Every minute', '5m' => 'Every 5 minutes']],
        'hide_when' => ['label' => 'Hide when', 'options' => ['never' => 'Never', 'ok' => 'All is ok', 'empty' => 'Empty']],
    ],

    'widget' => [
        'loading' => 'Loading...',
        'failed' => 'Could not load this widget.',
    ],

    'trend' => [
        'previous' => 'vs the previous period',
        'yesterday' => 'vs yesterday',
        'last_week' => 'vs last week',
    ],

    'gauge' => [
        'label' => ':value of :max',
    ],

    'chart' => [
        'label' => 'Chart of :points points, :total in all',
        'series' => [
            'requests' => 'Requests',
            'errors' => 'Errors',
            'previous' => 'Previous period',
        ],
    ],

    'release' => [
        'since' => 'out for :age',
        'previous' => 'after :release',
        'errors' => '5xx :rate',
    ],
    'list' => [
        'empty' => 'Nothing to show.',
        'oldest' => 'oldest :age',
        'host_usage' => 'CPU :cpu · memory :memory · disk :disk',
        'offline' => 'Offline',
        'usage' => ['cpu' => 'CPU :value', 'memory' => 'Memory :value', 'disk' => 'Disk :value'],
        'escalating' => 'Escalating',
        'regressed' => 'Regressed',
        'task_status' => [
            'finished' => 'Ran',
            'failed' => 'Failed',
            'missed' => 'Missed',
            'running' => 'Running',
            'skipped' => 'Skipped',
        ],
        'sort_hint' => [
            'latest_issues' => ['default' => 'newest first', 'newest' => 'newest first', 'most_frequent' => 'most frequent first'],
            'alert_feed' => ['default' => 'newest first'],
            'failed_jobs' => ['default' => 'most failures first'],
            'scheduled_tasks' => ['default' => 'failed first'],
            'slowest_routes' => ['default' => 'slowest first'],
            'queues' => ['default' => ''],
            'hosts' => ['default' => ''],
            'agents' => ['default' => ''],
        ],
        'issue_status' => [
            'open' => 'Open',
            'in_progress' => 'In progress',
            'resolved' => 'Resolved',
            'ignored' => 'Ignored',
        ],
    ],

    'states' => [
        'down' => 'Down',
        'degraded' => 'Degraded',
        'recovering' => 'Recovering',
        'stale' => 'Stale',
        'pending' => 'Pending',
        'ok' => 'OK',
    ],

    'health' => [
        'ok_of' => ':ok of :total ok',
        'all_pass' => 'All checks pass',
        'every_passes' => 'Every check passes',
        'for' => ':state for :time',
        'uptime' => ':uptime uptime',
        'more' => '+:count more',
    ],

    'kiosk' => [
        'all_projects' => 'All projects',
        'all_environments' => 'All environments',
        'attention' => '{1} 1 needs attention|[2,*] :count need attention',
        'live' => 'Live',
        'offline_since' => 'Offline since :time',
        'offline_title' => 'Lumberjack cannot be reached',
        'offline_body' => 'The numbers below are from :time and are not current. Trying again every 30 seconds.',
        'deleted' => 'This screen was deleted.',
        'unshared' => 'This screen was unshared.',
        'unshared_body' => 'The link no longer works. Ask the team for a new one.',
        'no_screen' => ':name shows no screen yet.',
        'no_screen_body' => 'Pick one for it under Team settings > Displays; it shows here right away.',
    ],

    // How a display draws its screen, on Team settings > Displays.
    'display_theme' => [
        'default' => 'Screen default',
        'dark' => 'Dark',
        'light' => 'Light',
        'schedule' => 'By time of day',
    ],

    // The TV's start page, /tv, while it waits to be paired. Read from across the room.
    'tv' => [
        'title' => 'Show a status screen on this TV',
        'step_scan' => 'Scan the code with your phone, or open :url',
        'step_code' => 'Enter this code if asked',
        'step_confirm' => 'Pick the screen and confirm',
        'code_label' => 'Pairing code :code',
        'qr_label' => 'QR code for :url',
        'waiting' => 'Waiting for confirmation',
        'new_code_in' => 'new code in :time',
        'paired' => 'Paired. Opening the screen...',
        // Which browser window of a computer this is, /tv/2 being window 2.
        'window' => 'Window :slot',
        'start_page' => 'Opened this page by hand? Make :url the start page of the TV browser, and it will show its screen after every restart.',
    ],

    // /pair, on the phone or laptop confirming a TV's code.
    'pair' => [
        'title' => 'Show a screen on a TV',
        'question' => 'Show a screen on this TV?',
        'enter' => 'Enter the code the TV shows',
        'code' => 'Code',
        // Button.
        'find' => 'Continue',
        'unknown' => 'No TV waits for the code :code. Codes last 10 minutes; check the TV for its current one.',
        'device' => ':device · code made :age',
        'unknown_device' => 'Unknown browser',
        'check' => 'Check that the TV shows the same code.',
        'screen' => 'Screen',
        'screen_option' => ':screen (:team)',
        'name' => 'Name this TV',
        'name_default' => 'Office TV',
        // Button.
        'confirm' => 'Show on the TV',
        'expires' => 'Not your TV? Close this page; the code expires :time.',
        'no_screens' => 'Only team admins pair TVs, and you administer no team with a screen. Ask a team admin, or make a screen first.',
        'done' => ':display shows :screen',
        'done_body' => 'Change what it shows, or remove it, under Team settings > Displays. No need to touch the TV again.',
        // Buttons.
        'another' => 'Pair another TV',
        'displays' => 'Go to Displays',
        'another_window' => 'Another screen on this computer? Open :url in a second window (or on the second monitor) and pair it the same way.',
    ],

    // How to put a screen on a TV, in the designer and on Displays.
    'open_tv' => [
        // Button in the designer's top bar.
        'button' => 'Open on a TV',
        'title' => 'Open on a TV',
        'step_open' => 'On the TV, open :url. It shows a code.',
        'step_scan' => 'Scan that code with your phone, or enter it at :url.',
        'step_pick' => 'Pick the screen and confirm. The TV shows it within a second.',
        'qr_hint' => 'This code opens :url, for a TV stick or kiosk browser with a camera, or to cast the tab from a laptop.',
        'code' => 'Code on the TV',
        // Button.
        'continue' => 'Continue',
    ],

    // The share link panel of the designer.
    'share' => [
        // Button in the designer's top bar.
        'button' => 'Share',
        'title' => 'Share link',
        'hint' => 'A link that shows this screen without signing in, for signage systems and pages that embed a URL. Prefer pairing a TV: it needs no link at all.',
        // Button.
        'enable' => 'Share with a link',
        'url' => 'Share link',
        'titles' => 'Show issue titles and names, not only counts',
        // Buttons.
        'rotate' => 'New link',
        'revoke' => 'Stop sharing',
        'rotate_question' => 'Make a new link? The current one stops working at once, on every screen that shows it.',
        'revoke_question' => 'Stop sharing? The link stops working at once, on every screen that shows it.',
        'download' => 'Download QR for printing',
        'qr_label' => 'QR code for the share link',
        'admins_only' => 'Only team admins share screens.',
        'off' => 'Not shared.',
    ],

    // Team settings > Displays.
    'displays' => [
        'title' => 'Displays',
        'description' => 'TVs that show a status screen. Open :url on a TV and confirm its code to add one. Changing the screen here switches the TV right away.',
        // Button.
        'pair' => 'Pair a TV',
        'empty_title' => 'No TVs yet',
        'empty_body' => 'Open :url on a TV and confirm the code it shows; it appears here.',
        'name' => 'Name',
        'shows' => 'Shows',
        'theme' => 'Theme',
        'device' => 'Device',
        'status' => 'Status',
        'actions' => 'Actions',
        'no_screen' => 'No screen',
        'online' => 'Online',
        'last_seen' => 'Last seen :time',
        'never_seen' => 'Never connected',
        // Button.
        'remove' => 'Remove',
        'remove_question' => 'Remove :name? The TV goes back to showing a pairing code.',
        'read_only' => 'Only team admins change displays.',
        // ":device: window 1, window 2" for a computer showing several screens.
        'computer' => ':device: :windows',
        'window' => 'window :slot',
        // Button; opens the playlist of a display.
        'rotate' => 'In turn',
        'rotating' => '{1} 1 screen|[2,*] :count screens in turn',
        'playlist_title' => 'Screens in turn on :name',
        'playlist_hint' => 'Tick the screens to show one after another, in this order. Fewer than two shows one screen.',
        'move_up' => 'Move :name up',
        'move_down' => 'Move :name down',
        'seconds' => 'Seconds per screen',
        'hold' => 'Stay on a screen while it has something red',
        // Button.
        'save' => 'Save',
    ],

    'validation' => [
        'source' => 'This widget cannot show that.',
        'duplicate_id' => 'Two widgets have the same id.',
    ],
];
