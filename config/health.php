<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Health checks Lumberjack runs itself
    |--------------------------------------------------------------------------
    |
    | HTTP, SSL and TCP checks refuse hosts that resolve to private, loopback,
    | link-local or reserved addresses, so a check cannot reach this server or
    | the network behind it. Self-hosters who want to watch internal services
    | can turn that off.
    |
    */

    'allow_private' => (bool) env('LUMBERJACK_HEALTH_ALLOW_PRIVATE', false),

    // Connected apps are pulled on addresses anyone administering a project can enter, so
    // private ones are refused even with the setting above on, unless this says otherwise.
    'data_sources_allow_private' => (bool) env('LUMBERJACK_DATA_SOURCES_ALLOW_PRIVATE', false),

    // The most checks Lumberjack runs for one project.
    'max_checks_per_project' => (int) env('LUMBERJACK_HEALTH_MAX_CHECKS', 50),

    // The low-priority queue the checks run on; the background workers take it after alerts.
    'queue' => 'health',

    // Pulls from connected apps have their own workers: a slow app waits only on these.
    'data_sources_queue' => 'data-sources',

    /*
    |--------------------------------------------------------------------------
    | Spelled words
    |--------------------------------------------------------------------------
    |
    | Words in a health check's slug that a capital letter alone does not
    | spell right, for the name shown when the check has none of its own.
    |
    */

    'spelled_words' => [
        // Databases
        'bigquery' => 'BigQuery',
        'clickhouse' => 'ClickHouse',
        'cockroachdb' => 'CockroachDB',
        'couchdb' => 'CouchDB',
        'db' => 'DB',
        'dynamodb' => 'DynamoDB',
        'elasticache' => 'ElastiCache',
        'influxdb' => 'InfluxDB',
        'keydb' => 'KeyDB',
        'mariadb' => 'MariaDB',
        'mongodb' => 'MongoDB',
        'mssql' => 'SQL Server',
        'mysql' => 'MySQL',
        'opensearch' => 'OpenSearch',
        'pgbouncer' => 'PgBouncer',
        'pgsql' => 'PostgreSQL',
        'planetscale' => 'PlanetScale',
        'postgres' => 'PostgreSQL',
        'postgresql' => 'PostgreSQL',
        'proxysql' => 'ProxySQL',
        'rds' => 'RDS',
        'sqlite' => 'SQLite',
        'sqlsrv' => 'SQL Server',
        'timescaledb' => 'TimescaleDB',

        // Protocols
        'amqp' => 'AMQP',
        'caldav' => 'CalDAV',
        'carddav' => 'CardDAV',
        'dns' => 'DNS',
        'ftp' => 'FTP',
        'ftps' => 'FTPS',
        'grpc' => 'gRPC',
        'http' => 'HTTP',
        'https' => 'HTTPS',
        'imap' => 'IMAP',
        'ipmi' => 'IPMI',
        'ipv4' => 'IPv4',
        'ipv6' => 'IPv6',
        'ldap' => 'LDAP',
        'mqtt' => 'MQTT',
        'nfs' => 'NFS',
        'ntp' => 'NTP',
        'oauth' => 'OAuth',
        'oidc' => 'OIDC',
        'pop3' => 'POP3',
        'saml' => 'SAML',
        'sftp' => 'SFTP',
        'smb' => 'SMB',
        'smtp' => 'SMTP',
        'snmp' => 'SNMP',
        'ssh' => 'SSH',
        'ssl' => 'SSL',
        'tcp' => 'TCP',
        'tls' => 'TLS',
        'url' => 'URL',
        'webdav' => 'WebDAV',
        'ws' => 'WS',
        'wss' => 'WSS',

        // Cloud
        'aws' => 'AWS',
        'bunnycdn' => 'BunnyCDN',
        'cdn' => 'CDN',
        'digitalocean' => 'DigitalOcean',
        'ebs' => 'EBS',
        'efs' => 'EFS',
        'gcp' => 'GCP',
        'gcs' => 'GCS',
        'minio' => 'MinIO',
        'onedrive' => 'OneDrive',
        'ovh' => 'OVH',
        'rustfs' => 'RustFS',
        'ses' => 'SES',
        'sharepoint' => 'SharePoint',
        'sns' => 'SNS',
        'sqs' => 'SQS',
        'vps' => 'VPS',
        'waf' => 'WAF',

        // Services
        'activemq' => 'ActiveMQ',
        'adguard' => 'AdGuard',
        'circleci' => 'CircleCI',
        'deepseek' => 'DeepSeek',
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'homeassistant' => 'Home Assistant',
        'hubspot' => 'HubSpot',
        'huggingface' => 'Hugging Face',
        'mailersend' => 'MailerSend',
        'mailhog' => 'MailHog',
        'mobilepay' => 'MobilePay',
        'nats' => 'NATS',
        'newrelic' => 'New Relic',
        'openai' => 'OpenAI',
        'pagerduty' => 'PagerDuty',
        'paypal' => 'PayPal',
        'pgadmin' => 'pgAdmin',
        'phpmyadmin' => 'phpMyAdmin',
        'pihole' => 'Pi-hole',
        'quickpay' => 'QuickPay',
        'rabbitmq' => 'RabbitMQ',
        'sendgrid' => 'SendGrid',
        'sogo' => 'SOGo',
        'sparkpost' => 'SparkPost',
        'woocommerce' => 'WooCommerce',
        'wordpress' => 'WordPress',
        'zeromq' => 'ZeroMQ',

        // Stack
        'ai' => 'AI',
        'api' => 'API',
        'apm' => 'APM',
        'ci' => 'CI',
        'clamav' => 'ClamAV',
        'cpu' => 'CPU',
        'crm' => 'CRM',
        'erp' => 'ERP',
        'ffmpeg' => 'FFmpeg',
        'fpm' => 'FPM',
        'frankenphp' => 'FrankenPHP',
        'glusterfs' => 'GlusterFS',
        'gpu' => 'GPU',
        'haproxy' => 'HAProxy',
        'imagemagick' => 'ImageMagick',
        'iops' => 'IOPS',
        'ocr' => 'OCR',
        'opcache' => 'OPcache',
        'opentelemetry' => 'OpenTelemetry',
        'openvpn' => 'OpenVPN',
        'opnsense' => 'OPNsense',
        'otel' => 'OTel',
        'pdf' => 'PDF',
        'pfsense' => 'pfSense',
        'php' => 'PHP',
        'raid' => 'RAID',
        'ram' => 'RAM',
        'roadrunner' => 'RoadRunner',
        'seo' => 'SEO',
        'sms' => 'SMS',
        'sso' => 'SSO',
        'truenas' => 'TrueNAS',
        'unifi' => 'UniFi',
        'vpn' => 'VPN',
        'wireguard' => 'WireGuard',
        'zfs' => 'ZFS',

        // Shorthands
        'cfg' => 'Config',
        'conn' => 'Connection',
        'conns' => 'Connections',
        'env' => 'Environment',
        'infra' => 'Infrastructure',
        'mem' => 'Memory',
        'mgmt' => 'Management',
        'mon' => 'Monitoring',
        'monitor' => 'Monitoring',
        'msg' => 'Messaging',
        'notif' => 'Notification',
        'notifs' => 'Notifications',
        'perf' => 'Performance',
        'proc' => 'Process',
        'procs' => 'Processes',
        'prod' => 'Production',
        'repl' => 'Replication',
        'sched' => 'Scheduler',
        'srv' => 'Server',
        'stg' => 'Staging',
        'svc' => 'Service',
    ],

];
