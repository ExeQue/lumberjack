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
        'blank' => ['label' => 'Empty', 'description' => 'An 8 x 4 grid to fill yourself.'],
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
        'shown_on' => 'Shown on',
        'now' => '{0} none now|{1} 1 now|[2,*] :count now',
        'now_hint' => 'Kiosks, paired TVs and share links with the screen open right now.',
        'tvs' => '{1} 1 paired TV|[2,*] :count paired TVs',
        'shared' => 'Shared',
        'shared_hint' => 'Readable by anyone with its link.',
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
        // Downloads the screen as a JSON file.
        'export' => 'Export',
        'export_hint' => 'Download this screen as a JSON file to import in another team or Lumberjack.',
        // Opens the menu with export, copy to team and save as template.
        'more' => 'More',
    ],

    // Copying a screen to another team of the user's.
    'copy' => [
        // Menu item.
        'button' => 'Copy to team...',
        'title' => 'Copy :name to another team',
        'hint' => 'A new screen in that team, like this one. Projects and health checks are found there by their slugs; any it does not have are left out and named.',
        'team' => 'Team',
        // Button.
        'submit' => 'Copy screen',
    ],

    // The team's own templates, saved from its screens.
    'saved_templates' => [
        // Menu item.
        'save' => 'Save as template',
        'save_title' => 'Save :name as a template',
        'save_hint' => 'New screen offers it to the whole team, next to the built-in layouts. Later changes to the screen do not change the template.',
        'name' => 'Name',
        'description' => 'Description',
        // Button.
        'submit' => 'Save template',
        'title' => 'Team templates',
        'list_hint' => 'Saved from the team\'s screens. Team admins can rename and delete them.',
        // In the New screen dialog, above the team's templates.
        'picker' => 'Saved by the team',
        'by' => 'by :name',
        'edit' => 'Edit',
        'edit_title' => 'Edit the template :name',
        'delete' => 'Delete',
        'delete_question' => 'Delete the template :name? Screens made from it stay as they are.',
    ],

    // Importing a screen from an exported JSON file.
    'import' => [
        // Button.
        'button' => 'Import',
        'title' => 'Import a screen',
        'hint' => 'Pick a file exported from Lumberjack, or paste its JSON. Projects and health checks are found by their slugs in this team.',
        'file' => 'File',
        'paste' => 'Or paste the JSON',
        'reading' => 'Reading the file...',
        'preview' => 'What gets imported',
        'grid' => ':cols x :rows grid',
        'widgets' => '{0} no widgets|{1} 1 widget|[2,*] :count widgets',
        'all_found' => 'Every project and health check it names exists in this team.',
        // Button.
        'submit' => 'Import screen',
        // What an import left out; :items lists them.
        'dropped' => 'Not found in this team, so left out: :items.',
        'dropped_project' => 'project :slug',
        'dropped_check' => 'check :check',
        'errors' => [
            'format' => 'This is not a Lumberjack screen file.',
            'version' => 'This screen file is from another version of Lumberjack; this one reads version :version.',
            'json' => 'This is not valid JSON.',
            'size' => 'The file is too large; a screen file is at most :max KB.',
            'missing' => 'Pick a file or paste its JSON.',
        ],
    ],

    'theme' => [
        'dark' => 'Dark',
        'light' => 'Light',
        'schedule' => 'Light by day, dark at night',
    ],

    'period_short' => ['now' => 'now', '1h' => '1h', '24h' => '24h', '7d' => '7d', '30d' => '30d'],
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
        'qr' => 'QR code',
        'media' => 'Image, video or page',
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
        'qr' => 'A link, the guest wifi or any text to scan',
        'media' => 'A picture or camera, a video, YouTube or a web page',
        'empty' => 'Blank space; not shown on the TV',
    ],

    'palette' => [
        'title' => 'Add a widget',
        'search' => 'Find a widget',
        'data' => 'Data',
        'layout' => 'Layout',
        'none' => 'No widget matches.',
        'hint' => 'Drag onto any free cell of the grid, or click to add in the first free spot.',
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
        'aspect' => 'Aspect',
        // The landscape and portrait buttons next to it.
        'orientation' => 'Orientation',
        // Button in the top bar: every TV showing the screen loads its page afresh.
        'reload' => 'Reload TVs',
        'reload_hint' => 'Every TV, kiosk and share link showing this screen loads its page afresh, within a few seconds.',
        'reload_failed' => 'The reload could not be sent.',
        // The designer's preview: as large as fits, or the TV's layout at a resolution.
        'preview_at' => 'Preview at',
        'preview_fit' => 'Fit',
        'preview_tvs' => 'On a TV now',
        'preview_common' => 'Common sizes',
        'preview_custom' => 'Custom',
        'preview_w' => 'Width in pixels',
        'preview_h' => 'Height in pixels',
        'theme' => 'Theme',
        // Toggle: the TV's name, scope, live state and clock above the grid.
        'show_header' => 'Show header',
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
        'overflow' => '{1} 1 widget does not fit on the :cols x :rows screen and does not show on the TV. Drag it back onto free cells, add columns or rows, or remove it.|[2,*] :count widgets do not fit on the :cols x :rows screen and do not show on the TV. Drag them back onto free cells, add columns or rows, or remove them.',
        'no_room' => 'No free cell left. Make room, or add columns or rows.',
        'help' => 'Drag a widget onto free cells to move it, any edge or corner to resize it; green shows where it fits, red where it does not. With a widget focused: arrows move it, Shift and arrows resize it, Delete removes it, Escape shows the screen settings.',
        'tile' => ':kind: :title, :w by :h',
        'tile_untitled' => ':kind, :w by :h',
    ],

    // Groups of what a widget can show, in the designer's list.
    'topics' => [
        'traffic' => 'Traffic',
        'issues' => 'Issues',
        'jobs' => 'Jobs and queues',
        'infrastructure' => 'Hosts and agents',
        'databases' => 'Databases',
        'health' => 'Health',
        'external' => 'External',
    ],
    'inspector' => [
        'label' => 'Settings',
        'period' => 'Over',
        'period_screen' => 'Screen default',
        // Button.
        'remove' => 'Remove',
        'add_url' => 'Add an address',
        'remove_url' => 'Remove this address',
        'title' => 'Title',
        'shows' => 'Shows',
        // The tabs of a rotating widget's values, and what applies to all of them.
        'values' => 'Values shown in turn',
        'add_value' => 'Add a value to show in turn',
        'remove_value' => 'Remove this value',
        'rotate_on' => 'Shows :count values in turn, each on its own tab. Drag a tab or use the arrows to change the order.',
        'rotate_off' => 'Add a value with + to show several in turn on this tile.',
        'rotate_seconds' => 'Shows for (seconds)',
        // A health value's tab, named after the checks it shows.
        'all_checks' => 'All checks',
        'some_checks' => '{1} 1 check|[2,*] :count checks',
        'same_as_widget' => 'Same as widget (:value)',
        'on' => 'On',
        'off' => 'Off',
        // Buttons that reorder the rotation.
        'move_earlier' => 'Show earlier',
        'move_later' => 'Show later',
        'whole_widget' => 'Whole widget',
        'whole_widget_hint' => 'Applies to every value.',
        // The gear in a designer tile's corner, and where Display went.
        'display_gear' => 'Display settings',
        'display_hint' => 'Display settings: the gear on the widget.',
        'display_copy' => 'Copy',
        'display_copied' => 'Copied',
        'display_paste' => 'Paste',
        'limits_above' => 'Turns amber and red at or above the limits; empty uses the defaults shown.',
        'limits_below' => 'Turns amber and red at or below the limits; empty uses the defaults shown.',
        'ratio_hint' => 'Shares go from 0 to 1: 0.05 is 5%.',
        'ms_hint' => 'In milliseconds.',
        'bytes_hint' => 'In bytes: 1073741824 is 1 GB.',
        'auto' => 'Automatic',
        'screen_default' => 'Screen default',
        'screen_timezone' => 'The screen\'s time zone',
        'all_projects' => 'All in the team',
        'all_environments' => 'All environments',
        'no_checks' => 'The team has no health checks yet.',
        'checks_hint' => 'None picked: every check of the projects, new ones included. One picked: that check alone, with its uptime and timeline.',
        'empty_hint' => 'Keeps this space empty on the TV. Resize it to make a gap of any size.',
    ],

    // The display a screen is designed for: its shape, then which way it stands.
    'aspect' => [
        '16:9' => '16:9',
        '16:10' => '16:10',
        '4:3' => '4:3',
        '21:9' => '21:9',
        '43:18' => '43:18',
        '32:9' => '32:9',
    ],
    'orientation' => [
        'landscape' => 'Landscape',
        'portrait' => 'Portrait',
    ],
    // On a display of another shape than the screen's.
    'fit' => [
        'keep' => 'Keep its shape',
        'fill' => 'Fill the display',
    ],

    'settings' => [
        'title' => 'Screen settings',
        'hint' => 'What every widget shows unless it picks its own. Click a widget to change it.',
        'name' => 'Name',
        'projects' => 'Projects',
        'projects_hint' => 'None ticked: every project of the team, new ones included.',
        'timezone' => 'Time zone',
        'timezone_hint' => 'For the clock and for switching between light and dark. Empty: each TV uses its own (the browser\'s) time zone.',
        'browser_timezone' => 'The browser\'s time zone',
        'show_clock' => 'Show the clock in the header',
        'fit' => 'On a different screen',
        'fit_hint' => 'A display of another shape than the screen\'s shows it as designed, centered with bars around it, or stretched to fill it.',
    ],

    'sections' => [
        'value' => 'Value',
        'scale' => 'Scale',
        'chart' => 'Chart',
        'rows' => 'Rows',
        'checks' => 'Checks',
        'clock' => 'Clock',
        'qr' => 'QR code',
        'media' => 'Media',
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
        'show_red_limit' => ['label' => 'Mark the red limit on the arc'],
        'compare' => ['label' => 'Compare with', 'options' => ['none' => 'Nothing', 'previous' => 'Previous period', 'yesterday' => 'Same time yesterday', 'last_week' => 'Same time last week']],
        'decimals' => ['label' => 'Decimals', 'options' => ['auto' => 'Automatic', '0' => '0', '1' => '1', '2' => '2']],
        'sparkline' => ['label' => 'Sparkline under the number'],
        'prefix' => ['label' => 'Prefix', 'placeholder' => 'e.g. ~'],
        'suffix' => ['label' => 'Suffix', 'placeholder' => 'e.g. /min'],
        'style' => ['label' => 'Style', 'options' => ['bars' => 'Bars', 'area' => 'Area', 'line' => 'Line']],
        'split_by' => ['label' => 'Split by', 'options' => ['none' => 'Nothing', 'route' => 'Route', 'queue' => 'Queue', 'host' => 'Host', 'check' => 'Database check', 'environment' => 'Environment', 'release' => 'Release', 'project' => 'Project']],
        'top' => ['label' => 'Series', 'options' => ['5' => 'Top 5', '10' => 'Top 10', '20' => 'Top 20']],
        'overlay_previous' => ['label' => 'Overlay the previous period'],
        'smooth' => ['label' => 'Smooth lines'],
        'limit_line' => ['label' => 'Draw the red limit'],
        'y_zero' => ['label' => 'Y axis starts at zero'],
        'legend' => ['label' => 'Legend'],
        'axis' => ['label' => 'Time axis'],
        'y_axis' => ['label' => 'Value axis'],
        'grid' => ['label' => 'Grid lines'],
        'limit' => ['label' => 'Rows', 'options' => ['auto' => 'Fit to tile', '3' => '3', '5' => '5', '10' => '10', '20' => '20']],
        'sort' => ['label' => 'Sort', 'options' => ['newest' => 'Newest first', 'worst' => 'Most important first', 'last_seen' => 'Last reported first', 'most_frequent' => 'Most frequent']],
        'only_problems' => ['label' => 'Only what needs attention'],
        'show_age' => ['label' => 'Show age'],
        'show_count' => ['label' => 'Show count'],
        'show_project' => ['label' => 'Show project'],
        'wrap' => ['label' => 'Wrap long text'],
        'auto_scroll' => ['label' => 'Scroll slowly through rows that do not fit'],
        'view' => ['label' => 'Show as', 'options' => ['auto' => 'Automatic', 'summary' => 'Summary', 'list' => 'List', 'cards' => 'Cards']],
        'checks' => ['label' => 'Checks'],
        'data_source' => ['label' => 'Data source', 'none' => 'Pick a data source', 'empty' => 'No data sources yet; add one on a project\'s Data sources page.'],
        'json_path' => ['label' => 'JSONPath', 'placeholder' => '$.data.total', 'selector' => 'Selector', 'selector_placeholder' => 'sum by (status) (http_requests_total{method="GET"})', 'help' => [
            'matches' => 'Matches: :value',
            'per_min' => 'counter, per min',
            'invalid' => 'Not a selector: name{label="value"}, optionally in sum or sum by (label)',
            'no_rate' => 'A counter: its rate shows after the next fetch',
            'series' => '{1} :type · 1 series|[2,*] :type · :count series',
            'selector_series' => '{1} 1 series|[2,*] :count series',
            'items' => '{0} No items|{1} 1 item|[2,*] :count items',
            'no_match' => 'No match',
            'not_number' => 'Not a number',
            'several' => [
                'stat' => 'Several matches (:count); a stat needs one',
                'gauge' => 'Several matches (:count); a gauge needs one',
                'chart' => 'Several matches (:count); a chart needs one',
            ],
            'suggestions' => 'Paths in the latest response',
            'latest' => 'Latest response',
            'fetched' => 'Fetched :time',
            'truncated' => 'only the start is shown',
            'not_fetched' => 'This data source has not answered yet; its paths show here after its first good fetch.',
            'configure' => 'Configure',
            'configure_selector' => 'Configure selector',
            'choose' => 'Choose a value…',
            'apply' => 'Apply',
            'pick_number' => 'Click a number in the response to read it, or type a JSONPath.',
            'pick_array' => 'Click an array in the response to list its items, or type a JSONPath.',
        ]],
        'label_path' => ['label' => 'Label path in each item', 'placeholder' => '$.name'],
        'value_path' => ['label' => 'Value path in each item', 'placeholder' => '$.count'],
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
        'content' => ['label' => 'Holds', 'options' => ['link' => 'A link', 'wifi' => 'A wifi network', 'text' => 'Text']],
        'url' => ['label' => 'Link', 'placeholder' => 'https://'],
        'ssid' => ['label' => 'Network name', 'placeholder' => 'Guests'],
        'password' => ['label' => 'Password', 'placeholder' => 'The network\'s password'],
        'security' => ['label' => 'Security', 'options' => ['WPA' => 'WPA', 'WEP' => 'WEP', 'nopass' => 'None']],
        'hidden_network' => ['label' => 'Hidden network'],
        'qr_text' => ['label' => 'Text', 'placeholder' => 'Anything a phone should read'],
        'caption' => ['label' => 'Line under the code', 'placeholders' => ['link' => 'Our status page', 'wifi' => 'Guest wifi', 'text' => 'Scan me', 'image' => 'The loading dock', 'video' => 'This week\'s release', 'page' => 'Grafana']],
        'media' => ['label' => 'Shows', 'options' => ['image' => 'An image or camera', 'video' => 'A video', 'page' => 'A web page or YouTube']],
        'media_url' => ['label' => 'Addresses', 'placeholder' => 'https://', 'hint' => 'Several show in turn, the same one on every TV at once.'],
        'slide_seconds' => ['label' => 'Next after', 'options' => ['5' => '5 seconds', '10' => '10 seconds', '20' => '20 seconds', '30' => '30 seconds', '60' => '1 minute', '300' => '5 minutes']],
        'padding' => ['label' => 'Space around it'],
        'subtitles' => ['label' => 'Subtitles (YouTube and Vimeo)'],
        'fit' => ['label' => 'Fit', 'options' => ['contain' => 'Whole (contain)', 'cover' => 'Fill, cropped (cover)', 'fill' => 'Stretch (fill)', 'none' => 'Actual size (none)', 'scale-down' => 'Shrink only (scale-down)']],
        'reload_every' => ['label' => 'Load again', 'options' => ['never' => 'Never', '1' => 'Every minute', '5' => 'Every 5 minutes', '15' => 'Every 15 minutes', '60' => 'Every hour']],
        'rotate_every' => ['label' => 'Switch every', 'options' => ['5' => '5 seconds', '10' => '10 seconds', '20' => '20 seconds', '30' => '30 seconds', '60' => '1 minute']],
        'transition' => ['label' => 'Transition', 'options' => ['fade' => 'Fade', 'slide' => 'Slide', 'flip' => 'Flip', 'none' => 'None']],
        'hold_problem' => ['label' => 'Stay on a value that needs attention'],
        'progress' => ['label' => 'Show a progress bar until the next value'],
        'source_seconds' => ['label' => 'Seconds the first value shows'],
        'projects' => ['label' => 'Projects'],
        'environment' => ['label' => 'Environment'],
        'period' => ['label' => 'Period'],
        'show_period' => ['label' => 'Show the period in the corner'],
        'release' => ['label' => 'Release', 'options' => ['all' => 'All', 'current' => 'Current only', 'previous' => 'Previous']],
        'filter' => [
            'label' => 'Filter',
            'placeholder' => 'route, queue, job, host or check; * matches anything',
            'on' => ['queue' => 'Queue', 'host' => 'Host', 'job' => 'Job', 'route' => 'Route', 'check' => 'Database check'],
            'all' => ['queue' => 'All queues; pick one, or use * as a wildcard', 'host' => 'All hosts; pick one, or use * as a wildcard', 'job' => 'All jobs; * matches anything', 'route' => 'All routes, by URI or name, such as /checkout* or orders.*', 'check' => 'All database checks; pick one, or use * as a wildcard'],
        ],
        'domain' => ['label' => 'Domain', 'placeholder' => 'All domains; pick one, or use * as a wildcard'],
        'show_title' => ['label' => 'Show title'],
        'status_colors' => ['label' => 'Color the tile by status'],
        'ok_border' => ['label' => 'Green border while all is ok'],
        'text_size' => ['label' => 'Text size', 'options' => ['auto' => 'Fit to tile', 's' => 'Small', 'm' => 'Medium', 'l' => 'Large', 'xl' => 'Huge']],
        'title_size' => ['label' => 'Title size', 'options' => ['s' => 'Small', 'm' => 'Medium', 'l' => 'Large', 'xl' => 'Huge']],
        'align' => ['label' => 'Align', 'options' => ['left' => 'Left', 'center' => 'Center']],
        'valign' => ['label' => 'Vertically', 'options' => ['top' => 'Top', 'middle' => 'Middle']],
        'refresh' => ['label' => 'Update', 'options' => ['live' => 'Live', '1m' => 'Every minute', '5m' => 'Every 5 minutes']],
        'hide_when' => ['label' => 'Hide when', 'options' => ['never' => 'Never', 'ok' => 'All is ok', 'empty' => 'Empty']],
    ],

    'widget' => [
        'loading' => 'Loading...',
        'failed' => 'Could not load this widget.',
    ],

    'media' => [
        'empty' => 'No address yet.',
        'invalid' => 'Only http and https addresses.',
        'failed' => 'Could not load it.',
    ],

    'qr' => [
        'empty' => 'Nothing to encode yet.',
        'invalid' => 'That is not a link a QR code can hold.',
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
        'workers' => '{0} no workers|{1} 1 worker|[2,*] :count workers',
        'host_usage' => 'CPU :cpu · memory :memory · disk :disk',
        'offline' => 'Offline',
        'queue' => ['waiting' => 'Waiting :age'],
        'meter' => ['cpu' => 'CPU', 'memory' => 'Mem', 'disk' => 'Disk'],
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
            'latest_issues' => ['default' => 'newest first', 'newest' => 'newest first', 'worst' => 'most important first', 'last_seen' => 'last reported first', 'most_frequent' => 'most frequent first'],
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
        'connections_of' => ':used of :max conns',
        'connections' => ':count conns',
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
        // /tv on a paired device, the landing page: which window this one shows.
        'choose_title' => 'Which window is this?',
        'choose_hint' => 'Pick the window of :device this one shows, or add another; use the arrow keys and Enter on a remote.',
        'choose_start_page' => 'Each window lives at its own address. For a screen that runs unattended, make that address its start page, such as :url, not this page.',
        'no_screen' => 'No screen yet',
        // Button.
        'add_window' => 'Add another window',
        'add_window_hint' => 'Pairs window :slot with a new code',
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
        'name' => 'Name this device',
        'name_hint' => 'The TV or computer, so its windows are listed together: "Office PC".',
        'name_default' => 'Office TV',
        // Pairing another window of a device the team has: only its screen is asked.
        'join' => 'Add window :slot to :device',
        'join_body' => 'This browser is :device already; window :slot joins it.',
        'screen_window' => 'Screen for window :slot',
        // Button.
        'confirm' => 'Show on the TV',
        'expires' => 'Not your TV? Close this page; the code expires :time.',
        'no_screens' => 'Only team admins pair TVs, and you administer no team with a screen. Ask a team admin, or make a screen first.',
        'done' => ':display shows :screen',
        'done_body' => 'Change what it shows, or remove it, under Team settings > Displays. No need to touch the TV again.',
        // Buttons.
        'another' => 'Pair another TV',
        'displays' => 'Go to Displays',
        'another_window' => 'Another screen on this device? Open :url in a second window (or on the second monitor) and pair it the same way; it joins :device.',
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
        'paired' => 'Or on a TV already paired',
        'show_here' => 'Show here',
        'shows_this' => 'Shows this screen',
        'paired_hint' => 'It shows this screen instead of what it showed, playlist included, within a second.',
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
        'qr_label' => 'QR code for the share link',
        'admins_only' => 'Only team admins share screens.',
        'off' => 'Not shared.',
    ],

    // Team settings > Displays: one card per device, its windows under it.
    'displays' => [
        'title' => 'Displays',
        'description' => 'TVs and computers that show a status screen, each with its windows. Open :url on a TV and confirm its code to add one. Changing the screen here switches the TV right away.',
        // Button.
        'pair' => 'Pair a TV',
        'empty_title' => 'No TVs yet',
        'empty_body' => 'Open :url on a TV and confirm the code it shows; it appears here.',
        'device_name' => 'Name of :name',
        'window_col' => 'Window',
        'shows' => 'Shows',
        'seconds_col' => 'Seconds',
        'hold_col' => 'Holds on red',
        'theme' => 'Theme',
        'rotation' => 'Rotation',
        'rotation_hint' => 'For a TV hung sideways or upside down that cannot turn its picture itself.',
        'rotations' => ['0' => 'None', '90' => '90° right', '180' => '180°', '270' => '90° left'],
        'viewport' => 'Size',
        'status' => 'Status',
        'actions' => 'Actions',
        'yes' => 'Yes',
        'no' => 'No',
        'no_screen' => 'No screen',
        'unknown_viewport' => 'Not reported',
        'online' => 'Online',
        'last_seen' => 'Last seen :time',
        'never_seen' => 'Never connected',
        'windows' => '{1} 1 window|[2,*] :count windows',
        // Buttons: the TV loads its page afresh.
        'reload' => 'Reload',
        'reload_hint' => 'Loads the page in this window afresh, as after an update.',
        'reload_device' => 'Reload all',
        'reload_device_hint' => 'Loads the page in every window of this device afresh.',
        'remove' => 'Remove',
        'remove_question' => 'Remove :name? The window goes back to showing a pairing code.',
        // Button.
        'remove_device' => 'Remove device',
        'remove_device_question' => 'Remove :name? Every window of it goes back to showing a pairing code.',
        'read_only' => 'Only team admins change displays.',
        'add_window' => 'Add a window: open :url in another browser window on this device and pair it.',
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
        'overlap' => 'This widget covers a cell another widget already uses.',
    ],
];
