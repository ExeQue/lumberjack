<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * What the showcase projects are: their teams, people, routes, releases, jobs,
 * schedules and checks. The seeders turn it into rows; nothing here touches the
 * database. Latencies are in milliseconds, shares between 0 and 1, times in days
 * or minutes before now. Queries and spans are keyed by route, or by "METHOD route"
 * where two methods share the path.
 */
final class ShowcaseCatalog
{
    /** Faker and mt_rand start here, so every seed gives the same data. */
    public const int SEED = 20261103;

    /** Days of sampled request runs, which also carry the releases. */
    public const int RUN_DAYS = 30;

    /** Days of exact per-minute request counts; older traffic is told by the runs alone. */
    public const int COUNT_DAYS = 7;

    /** Days of job runs, queue samples, scheduled runs and commands. */
    public const int JOB_DAYS = 7;

    /** Days of health results, outages and host samples. */
    public const int HEALTH_DAYS = 14;

    /** Days of log lines. */
    public const int LOG_DAYS = 3;

    /** Every password of the showcase people. */
    public const string PASSWORD = 'password';

    /**
     * Teams, with the people added to each. The Local Team and Acme Agency are the
     * ones DatabaseSeeder makes; the showcase only adds people to them.
     *
     * @return array<string, list<array{name: string, email: string, role: string}>>
     */
    public static function teams(): array
    {
        return [
            'Local Team' => [
                ['name' => 'Maja Lindqvist', 'email' => 'maja@lumberjack', 'role' => 'admin'],
                ['name' => 'Oskar Brandt', 'email' => 'oskar@lumberjack', 'role' => 'member'],
                ['name' => 'Ida Holm', 'email' => 'ida@lumberjack', 'role' => 'member'],
            ],
            'Acme Agency' => [
                ['name' => 'Clara Acme', 'email' => 'clara@lumberjack', 'role' => 'admin'],
            ],
            'Northwind Labs' => [
                ['name' => 'Freja Nordahl', 'email' => 'freja@lumberjack', 'role' => 'owner'],
                ['name' => 'Emil Strand', 'email' => 'emil@lumberjack', 'role' => 'admin'],
                ['name' => 'Asta Kjaer', 'email' => 'asta@lumberjack', 'role' => 'member'],
            ],
        ];
    }

    /**
     * The showcase projects.
     *
     * @return list<array<string, mixed>>
     */
    public static function projects(): array
    {
        return [self::storefront(), self::ordersApi(), self::marketing(), self::worker(), self::booking(), self::portal(), self::helpdesk(), self::planner()];
    }

    /**
     * A webshop: busy, browser pages with web vitals, a checkout that broke in one release.
     *
     * @return array<string, mixed>
     */
    private static function storefront(): array
    {
        return [
            'team' => 'Local Team',
            'name' => 'Storefront',
            'slug' => 'storefront',
            'prefix' => 'SHOP',
            'domain' => 'shop.example.com',
            'peak' => 60,
            'sample_rate' => 0.05,
            'hosts' => ['production' => ['web-1', 'web-2', 'web-3'], 'staging' => ['staging-web-1']],
            'workers' => ['production' => ['worker-1'], 'staging' => ['staging-web-1']],
            'machine' => [4, 8, 80],
            'releases' => [
                ['v2.12.0', 29.4], ['v2.12.1', 27.2], ['v2.13.0', 23.6], ['v2.13.1', 20.1], ['v2.14.0', 16.5],
                ['v2.14.1', 13.3], ['v2.15.0', 9.6], ['v2.15.1', 6.4],
                // Deployed on a Thursday morning, rolled back that afternoon, fixed the next day.
                ['v2.16.0', 4.15, 'bad'], ['v2.15.1', 3.9], ['v2.16.1', 2.6], ['v2.16.2', 0.9],
            ],
            'routes' => [
                ['GET', '/', 'home', 14, 85],
                ['GET', '/products', 'products.index', 12, 140],
                ['GET', '/products/{slug}', 'products.show', 26, 110],
                ['GET', '/search', 'search', 8, 320, ['slow' => 0.04]],
                ['GET', '/cart', 'cart.show', 7, 95],
                ['POST', '/cart/items', 'cart.items.store', 5, 130, ['422' => 0.03]],
                ['GET', '/checkout', 'checkout.show', 3, 180],
                ['POST', '/checkout', 'checkout.store', 1.6, 640, ['500' => 0.004, '422' => 0.05, 'bad500' => 0.09]],
                ['POST', '/checkout/payment', 'checkout.payment', 1.2, 820, ['402' => 0.03]],
                ['GET', '/account/orders', 'account.orders.index', 2, 260],
                ['GET', '/account/orders/{order}', 'account.orders.show', 1.4, 190, ['404' => 0.02]],
                ['POST', '/login', 'login', 1.5, 210, ['302' => 0.9, '422' => 0.08]],
                ['GET', '/sitemap.xml', 'sitemap', 0.4, 1900],
                ['POST', '/webhooks/stripe', 'webhooks.stripe', 0.6, 120],
                ['GET', '/admin/reports/sales', 'admin.reports.sales', 0.15, 3800, ['domain' => 'admin.shop.example.com']],
            ],
            'pages' => ['/', '/products', '/products/{slug}', '/search', '/cart', '/checkout'],
            'queries' => [
                '/products' => [['select * from "products" where "published" = ? order by "position" asc limit 24', 'app/Http/Controllers/ProductController.php:28', 4]],
                // The N+1: one variants query per product on the listing.
                '/products/{slug}' => [
                    ['select * from "products" where "slug" = ? limit 1', 'app/Http/Controllers/ProductController.php:41', 1.2],
                    ['select * from "product_variants" where "product_id" = ?', 'app/Models/Product.php:88', 0.6, 18],
                    ['select * from "reviews" where "product_id" = ? order by "created_at" desc limit 10', 'app/Http/Controllers/ProductController.php:47', 2.4],
                ],
                '/search' => [['select * from "products" where to_tsvector(\'english\', "name" || \' \' || "description") @@ plainto_tsquery(?) limit 48', 'app/Search/ProductSearch.php:63', 140]],
                '/cart' => [['select * from "cart_items" where "cart_id" = ?', 'app/Http/Controllers/CartController.php:22', 1.1]],
                'POST /checkout' => [
                    ['select * from "carts" where "id" = ? limit 1', 'app/Http/Controllers/CheckoutController.php:31', 0.9],
                    ['insert into "orders" ("customer_id", "total", "currency", "status", "created_at", "updated_at") values (?, ?, ?, ?, ?, ?) returning "id"', 'app/Actions/PlaceOrderAction.php:54', 3.2],
                    ['update "product_variants" set "stock" = "stock" - ? where "id" = ?', 'app/Actions/PlaceOrderAction.php:71', 2.1, 3],
                ],
                '/account/orders' => [['select * from "orders" where "customer_id" = ? order by "created_at" desc limit 20', 'app/Http/Controllers/Account/OrderController.php:19', 6]],
                // The slow report query.
                '/admin/reports/sales' => [['select date_trunc(\'day\', "created_at") as day, sum("total") as revenue, count(*) as orders from "orders" where "created_at" >= ? group by 1 order by 1', 'app/Reports/SalesReport.php:37', 2900]],
            ],
            'spans' => [
                'POST /checkout/payment' => [['http', 'POST', 'api.stripe.com/v1/payment_intents', 480]],
                'POST /checkout' => [['http', 'POST', 'api.stripe.com/v1/payment_intents/confirm', 360], ['mail', 1], ['notification', 'App\\Notifications\\OrderPlaced', 'mail']],
                '/products/{slug}' => [['cache', 'product:{slug}', true], ['cache', 'reviews:summary', false]],
                '/' => [['cache', 'home:featured', true]],
            ],
            'queues' => [
                'default' => [['App\\Jobs\\SyncInventory', 300, 0.002], ['App\\Listeners\\UpdateSearchIndex', 120, 0]],
                'mail' => [['App\\Mail\\OrderConfirmation', 420, 0.004], ['App\\Notifications\\ShippingUpdate', 260, 0]],
                'webhooks' => [['App\\Jobs\\HandleStripeWebhook', 180, 0.01]],
            ],
            'jobs_per_hour' => 140,
            'backlog' => null,
            'schedule' => [
                ['artisan inventory:sync', '*/15 * * * *', 42_000],
                ['artisan sitemap:generate', '0 3 * * *', 95_000],
                ['artisan carts:prune-abandoned', '0 * * * *', 3_200],
                ['artisan reports:daily-sales', '30 6 * * *', 18_000, ['fail_days' => [2]]],
            ],
            'commands' => [['migrate', ['--force' => true]], ['optimize', []], ['horizon:terminate', []], ['catalog:import', ['--file' => 'spring-collection.csv']]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['reported', 'cache:redis', 'cache'],
                ['reported', 'disk:local', 'disk'],
                ['reported', 'queue:redis:default', 'queue'],
                // Stripe answered 503 half an hour ago; fine again for the last few minutes.
                ['reported', 'payments-api', 'closure', ['incidents' => [[6.2, 9, 'degraded', 'Stripe API answered in 2.4 s'], [0.0208, 27, 'down', 'Stripe API answered 503 Service Unavailable']]]],
                // The search worker stopped reporting three hours ago.
                ['reported', 'search-index', 'closure', ['stale' => 0.125]],
                ['monitored', 'http', 'Storefront', ['url' => 'https://shop.example.com/up', 'incidents' => [[4.1, 12, 'down', 'Expected HTTP 200, got 500']]]],
                ['monitored', 'ssl', 'Certificate', ['host' => 'shop.example.com']],
                ['monitored', 'heartbeat', 'Nightly backup', ['grace_minutes' => 120, 'incidents' => [[0.9, null, 'down', 'No ping since the backup of two nights ago']]]],
            ],
            'log_channels' => ['default', 'payments', 'search'],
        ];
    }

    /**
     * A SaaS API: JSON only, many small requests, a few slow exports.
     *
     * @return array<string, mixed>
     */
    private static function ordersApi(): array
    {
        return [
            'team' => 'Local Team',
            'name' => 'Orders API',
            'slug' => 'orders-api',
            'prefix' => 'API',
            'domain' => 'api.example.com',
            'peak' => 70,
            'sample_rate' => 0.02,
            'hosts' => ['production' => ['api-1', 'api-2'], 'staging' => ['staging-api-1']],
            'workers' => ['production' => ['api-worker-1'], 'staging' => ['staging-api-1']],
            'machine' => [8, 16, 120],
            'releases' => [
                ['3.8.0', 28.6], ['3.8.1', 24.1], ['3.9.0', 19.7], ['3.9.1', 15.2], ['3.10.0', 11.5],
                ['3.10.1', 8.3], ['3.11.0', 5.1], ['3.11.1', 1.8],
            ],
            'routes' => [
                ['GET', '/api/v1/orders', 'api.orders.index', 22, 70],
                ['GET', '/api/v1/orders/{order}', 'api.orders.show', 30, 35, ['404' => 0.03]],
                ['POST', '/api/v1/orders', 'api.orders.store', 9, 160, ['422' => 0.06]],
                ['PATCH', '/api/v1/orders/{order}', 'api.orders.update', 4, 95, ['409' => 0.02]],
                ['GET', '/api/v1/customers/{customer}', 'api.customers.show', 12, 40],
                ['GET', '/api/v1/products', 'api.products.index', 10, 55],
                ['POST', '/api/v1/webhooks', 'api.webhooks.store', 3, 30],
                ['GET', '/api/v1/exports/{export}', 'api.exports.show', 0.6, 2400, ['slow' => 0.2]],
                ['POST', '/oauth/token', 'passport.token', 4, 120, ['401' => 0.04]],
                ['GET', '/api/v1/me', 'api.me', 6, 25, ['401' => 0.01, '429' => 0.006]],
            ],
            'pages' => [],
            'queries' => [
                '/api/v1/orders' => [['select * from "orders" where "account_id" = ? order by "created_at" desc limit 50', 'app/Http/Controllers/Api/OrderController.php:24', 3.5], ['select count(*) as aggregate from "orders" where "account_id" = ?', 'app/Http/Controllers/Api/OrderController.php:24', 1.8]],
                '/api/v1/orders/{order}' => [['select * from "orders" where "id" = ? and "account_id" = ? limit 1', 'app/Http/Controllers/Api/OrderController.php:40', 0.7], ['select * from "order_lines" where "order_id" in (?)', 'app/Http/Resources/OrderResource.php:31', 1.1]],
                'POST /api/v1/orders' => [['insert into "orders" ("account_id", "reference", "total", "created_at", "updated_at") values (?, ?, ?, ?, ?) returning "id"', 'app/Actions/CreateOrderAction.php:38', 2.6]],
                '/api/v1/exports/{export}' => [['select "orders".*, "customers"."email" from "orders" inner join "customers" on "customers"."id" = "orders"."customer_id" where "orders"."account_id" = ? and "orders"."created_at" between ? and ?', 'app/Exports/OrderExport.php:52', 1700]],
                '/oauth/token' => [['select * from "oauth_clients" where "id" = ? limit 1', 'vendor/laravel/passport/src/ClientRepository.php:47', 0.6]],
            ],
            'spans' => [
                '/api/v1/orders' => [['cache', 'account:{id}:orders', true]],
                'POST /api/v1/webhooks' => [['redis', 'LPUSH', 'webhooks:pending']],
            ],
            'queues' => [
                'default' => [['App\\Jobs\\DeliverWebhook', 220, 0.03], ['App\\Jobs\\RecalculateTotals', 90, 0]],
                'exports' => [['App\\Jobs\\BuildOrderExport', 48_000, 0.02]],
            ],
            'jobs_per_hour' => 220,
            'backlog' => null,
            'schedule' => [
                ['artisan passport:purge', '0 */6 * * *', 1_400],
                ['artisan webhooks:retry-failed', '*/5 * * * *', 2_600],
                ['artisan accounts:bill', '0 2 * * *', 210_000],
            ],
            'commands' => [['migrate', ['--force' => true]], ['passport:keys', []], ['accounts:recalculate', ['account' => '1042']]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['reported', 'cache:redis', 'cache'],
                ['reported', 'queue:redis:exports', 'queue'],
                ['monitored', 'http', 'API', ['url' => 'https://api.example.com/up', 'incidents' => [[9.3, 4, 'down', 'Connection timed out after 10 s']]]],
                ['monitored', 'ssl', 'API certificate', ['host' => 'api.example.com', 'days_left' => 9, 'incidents' => [[5, null, 'degraded', 'Certificate expires in 9 days']]]],
                ['monitored', 'dns', 'api.example.com', ['host' => 'api.example.com']],
            ],
            'log_channels' => ['default', 'webhooks'],
        ];
    }

    /**
     * A marketing site: little traffic, mostly static pages and a contact form.
     *
     * @return array<string, mixed>
     */
    private static function marketing(): array
    {
        return [
            'team' => 'Local Team',
            'name' => 'Marketing Site',
            'slug' => 'marketing-site',
            'prefix' => 'WWW',
            'domain' => 'www.example.com',
            'peak' => 18,
            'sample_rate' => 0.1,
            'hosts' => ['production' => ['www-1']],
            'workers' => ['production' => ['www-1']],
            'machine' => [2, 4, 40],
            'releases' => [['1.21.0', 26.0], ['1.22.0', 12.4], ['1.22.1', 3.3]],
            'routes' => [
                ['GET', '/', 'home', 40, 60],
                ['GET', '/pricing', 'pricing', 16, 70],
                ['GET', '/blog', 'blog.index', 10, 110],
                ['GET', '/blog/{slug}', 'blog.show', 22, 90, ['404' => 0.04]],
                ['GET', '/about', 'about', 5, 55],
                ['POST', '/contact', 'contact.store', 1, 380, ['422' => 0.1]],
                ['POST', '/newsletter', 'newsletter.store', 1.5, 240],
            ],
            'pages' => ['/', '/pricing', '/blog', '/blog/{slug}'],
            'queries' => [
                '/blog/{slug}' => [['select * from "posts" where "slug" = ? and "published_at" <= ? limit 1', 'app/Http/Controllers/BlogController.php:33', 1.4]],
                '/blog' => [['select * from "posts" where "published_at" <= ? order by "published_at" desc limit 12', 'app/Http/Controllers/BlogController.php:18', 2.2]],
            ],
            'spans' => [
                'POST /newsletter' => [['http', 'POST', 'us21.api.mailchimp.com/3.0/lists/members', 520]],
                'POST /contact' => [['mail', 1]],
            ],
            'queues' => ['default' => [['App\\Jobs\\SubscribeToNewsletter', 600, 0.01]]],
            'jobs_per_hour' => 6,
            'backlog' => null,
            'schedule' => [['artisan sitemap:generate', '0 4 * * *', 8_000], ['artisan blog:publish-scheduled', '*/10 * * * *', 900, ['missed' => true]]],
            'commands' => [['migrate', ['--force' => true]]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['reported', 'disk:local', 'disk', ['used' => 0.86, 'incidents' => [[2.1, null, 'degraded', '86.4% used']]]],
                ['monitored', 'http', 'Website', ['url' => 'https://www.example.com']],
                ['monitored', 'ssl', 'Certificate', ['host' => 'www.example.com']],
            ],
            'log_channels' => ['default'],
        ];
    }

    /**
     * A background worker: no web traffic, queues and schedules all day, one backlog.
     *
     * @return array<string, mixed>
     */
    private static function worker(): array
    {
        return [
            'team' => 'Local Team',
            'name' => 'Fulfillment Worker',
            'slug' => 'fulfillment-worker',
            'prefix' => 'FUL',
            'domain' => null,
            'peak' => 0,
            'sample_rate' => 1.0,
            'hosts' => ['production' => ['fulfillment-1', 'fulfillment-2']],
            'workers' => ['production' => ['fulfillment-1', 'fulfillment-2']],
            'machine' => [4, 16, 100],
            'releases' => [['0.9.0', 21.0], ['0.9.1', 10.2], ['0.10.0', 2.2]],
            'routes' => [],
            'pages' => [],
            'queries' => [],
            'spans' => [],
            'queues' => [
                'default' => [['App\\Jobs\\AllocateStock', 450, 0.004], ['App\\Jobs\\SyncCarrierRates', 1_800, 0.01]],
                'labels' => [['App\\Jobs\\PrintShippingLabel', 2_200, 0.02]],
                'notifications' => [['App\\Notifications\\ParcelDispatched', 300, 0.003]],
                // Long runs, a few of which hang until the worker kills them.
                'imports' => [['App\\Jobs\\ImportSupplierFeed', 95_000, 0.05, 'stuck']],
            ],
            'jobs_per_hour' => 420,
            // The labels queue backs up when the carrier API slows down.
            'backlog' => ['queue' => 'labels', 'start' => 2.0, 'hours' => 5],
            'schedule' => [
                ['artisan carriers:sync-rates', '0 * * * *', 61_000],
                ['artisan shipments:track', '*/10 * * * *', 14_000, ['fail_days' => [1, 6]]],
                ['artisan suppliers:import', '15 1 * * *', 380_000],
                ['artisan warehouse:reconcile', '0 5 * * 1-5', 120_000, ['missed' => true]],
            ],
            'commands' => [['queue:retry', ['id' => ['all']]], ['warehouse:recount', ['--location' => 'A-14']], ['horizon:terminate', []]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['reported', 'queue:redis:labels', 'queue', ['incidents' => [[1.97, 255, 'down', '4,210 waiting, the oldest for 41 minutes']]]],
                ['reported', 'queue:redis:default', 'queue'],
                ['reported', 'carrier-api', 'closure', ['incidents' => [[11.4, 22, 'degraded', 'Carrier API answered in 4.8 s'], [7.2, 15, 'degraded', 'Carrier API answered in 5.3 s'], [2.0, 290, 'degraded', 'Carrier API answered in 9.1 s']]]],
                ['monitored', 'heartbeat', 'Supplier import', ['grace_minutes' => 60]],
                ['monitored', 'tcp', 'Label printer', ['host' => '10.20.0.15', 'port' => 9100]],
            ],
            'log_channels' => ['default', 'carriers'],
        ];
    }

    /**
     * Acme Agency's client project, small and quiet.
     *
     * @return array<string, mixed>
     */
    private static function booking(): array
    {
        return [
            'team' => 'Acme Agency',
            'name' => 'Acme Booking',
            'slug' => 'acme-booking',
            'prefix' => 'BOOK',
            'domain' => 'booking.acme.example',
            'peak' => 14,
            'sample_rate' => 0.1,
            'hosts' => ['production' => ['booking-1']],
            'workers' => ['production' => ['booking-1']],
            'machine' => [2, 4, 40],
            'releases' => [['1.4.0', 18.0], ['1.4.1', 6.5]],
            'routes' => [
                ['GET', '/', 'home', 20, 90],
                ['GET', '/rooms/{room}', 'rooms.show', 30, 120],
                ['GET', '/availability', 'availability', 18, 260],
                ['POST', '/bookings', 'bookings.store', 3, 540, ['422' => 0.08, '500' => 0.004]],
            ],
            'pages' => ['/', '/rooms/{room}'],
            'queries' => ['/availability' => [['select * from "bookings" where "room_id" = ? and "starts_at" < ? and "ends_at" > ?', 'app/Availability/Calendar.php:44', 9]]],
            'spans' => [],
            'queues' => ['default' => [['App\\Mail\\BookingConfirmed', 380, 0.005]]],
            'jobs_per_hour' => 8,
            'backlog' => null,
            'schedule' => [['artisan bookings:send-reminders', '0 8 * * *', 6_000]],
            'commands' => [['migrate', ['--force' => true]]],
            'checks' => [
                ['reported', 'database:mysql', 'db'],
                ['monitored', 'http', 'Booking site', ['url' => 'https://booking.acme.example']],
            ],
            'log_channels' => ['default'],
        ];
    }

    /**
     * Northwind Labs' customer portal.
     *
     * @return array<string, mixed>
     */
    private static function portal(): array
    {
        return [
            'team' => 'Northwind Labs',
            'name' => 'Customer Portal',
            'slug' => 'customer-portal',
            'prefix' => 'CP',
            'domain' => 'portal.northwind.example',
            'peak' => 30,
            'sample_rate' => 0.05,
            'hosts' => ['production' => ['portal-1', 'portal-2'], 'staging' => ['portal-staging']],
            'workers' => ['production' => ['portal-1'], 'staging' => ['portal-staging']],
            'machine' => [4, 8, 60],
            'releases' => [['2026.10.1', 27.0], ['2026.10.2', 17.0], ['2026.10.3', 8.0], ['2026.11.1', 1.2]],
            'routes' => [
                ['GET', '/dashboard', 'dashboard', 30, 150],
                ['GET', '/invoices', 'invoices.index', 14, 210],
                ['GET', '/invoices/{invoice}/pdf', 'invoices.pdf', 4, 1400],
                ['GET', '/tickets', 'tickets.index', 10, 130],
                ['POST', '/tickets', 'tickets.store', 2, 300, ['422' => 0.07]],
                ['POST', '/login', 'login', 3, 220, ['302' => 0.92, '422' => 0.06]],
            ],
            'pages' => ['/dashboard', '/invoices', '/tickets'],
            'queries' => ['/invoices' => [['select * from "invoices" where "customer_id" = ? order by "issued_at" desc limit 25', 'app/Http/Controllers/InvoiceController.php:21', 4.4]]],
            'spans' => ['/invoices/{invoice}/pdf' => [['http', 'POST', 'gotenberg:3000/forms/chromium/convert/html', 1100]]],
            'queues' => ['default' => [['App\\Jobs\\SyncInvoicesFromErp', 5_500, 0.03]]],
            'jobs_per_hour' => 20,
            'backlog' => null,
            'schedule' => [['artisan erp:sync', '*/30 * * * *', 41_000]],
            'commands' => [['migrate', ['--force' => true]]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['reported', 'cache:redis', 'cache'],
                ['monitored', 'http', 'Portal', ['url' => 'https://portal.northwind.example/login']],
            ],
            'log_channels' => ['default'],
        ];
    }

    /**
     * A Livewire app: most of its traffic is the notification bell polling, and each
     * update is its own route (livewire:component@method), the components and calls of
     * each in its context. A Livewire route's extra names the kind and its components:
     * name, class and the methods it called, and the page they live on.
     *
     * @return array<string, mixed>
     */
    private static function helpdesk(): array
    {
        $livewire = fn (array $components, string $page = '/tickets'): array => ['kind' => 'livewire', 'components' => $components, 'page' => $page];
        $reply = [
            ['insert into "replies" ("ticket_id", "user_id", "body", "created_at", "updated_at") values (?, ?, ?, ?, ?) returning "id"', 'app/Livewire/Tickets/ReplyForm.php:52', 2.6],
            ['update "tickets" set "status" = ?, "updated_at" = ? where "id" = ?', 'app/Livewire/Tickets/ReplyForm.php:58', 1.8],
        ];

        return [
            'team' => 'Local Team',
            'name' => 'Helpdesk',
            'slug' => 'helpdesk',
            'prefix' => 'DESK',
            'domain' => 'desk.example.com',
            'peak' => 40,
            'sample_rate' => 0.05,
            'hosts' => ['production' => ['desk-1', 'desk-2']],
            'workers' => ['production' => ['desk-1']],
            'machine' => [4, 8, 60],
            'releases' => [['3.8.0', 24.0], ['3.8.1', 15.5], ['3.9.0', 4.2]],
            'routes' => [
                ['GET', '/tickets', 'tickets.index', 6, 170],
                ['GET', '/tickets/{ticket}', 'tickets.show', 5, 150, ['404' => 0.01]],
                ['POST', 'livewire:notifications.bell@$refresh', 'livewire.update', 40, 28, $livewire([['notifications.bell', 'App\\Livewire\\Notifications\\Bell', ['$refresh'], 0]])],
                ['POST', 'livewire:tickets.table@(update)', 'livewire.update', 9, 140, $livewire([['tickets.table', 'App\\Livewire\\Tickets\\Table', [], 1]])],
                ['POST', 'livewire:tickets.table@__dispatch:ticket-updated', 'livewire.update', 3, 210, $livewire([['tickets.table', 'App\\Livewire\\Tickets\\Table', ['__dispatch:ticket-updated'], 0]])],
                ['POST', 'livewire:tickets.reply-form@save', 'livewire.update', 2, 640, ['422' => 0.05, '500' => 0.004, 'slow' => 0.04, ...$livewire([['tickets.reply-form', 'App\\Livewire\\Tickets\\ReplyForm', ['save'], 2]], '/tickets/{ticket}')]],
                ['POST', 'livewire:(batch)', 'livewire.update', 1.2, 470, $livewire([['tickets.reply-form', 'App\\Livewire\\Tickets\\ReplyForm', ['save'], 1], ['tickets.sidebar', 'App\\Livewire\\Tickets\\Sidebar', ['$refresh'], 0]], '/tickets/{ticket}')],
                ['POST', '/login', 'login', 1, 210, ['302' => 0.9, '422' => 0.08]],
            ],
            'pages' => ['/tickets', '/tickets/{ticket}'],
            'queries' => [
                'livewire:notifications.bell@$refresh' => [['select count(*) as aggregate from "notifications" where "notifiable_id" = ? and "read_at" is null', 'app/Livewire/Notifications/Bell.php:24', 1.4]],
                'livewire:tickets.table@(update)' => [['select * from "tickets" where "subject" ilike ? order by "updated_at" desc limit 25', 'app/Livewire/Tickets/Table.php:61', 48]],
                'livewire:tickets.reply-form@save' => $reply,
                'livewire:(batch)' => [...$reply, ['select * from "tickets" where "assignee_id" = ? and "status" = ? order by "updated_at" desc limit 10', 'app/Livewire/Tickets/Sidebar.php:31', 3.4]],
            ],
            'spans' => [
                'livewire:notifications.bell@$refresh' => [['livewire', 'call', 'notifications.bell@$refresh', 4], ['livewire', 'render', 'notifications.bell', 9]],
                'livewire:tickets.table@(update)' => [['livewire', 'render', 'tickets.table', 70]],
                'livewire:tickets.table@__dispatch:ticket-updated' => [['livewire', 'call', 'tickets.table@__dispatch:ticket-updated', 120], ['livewire', 'render', 'tickets.table', 70]],
                'livewire:tickets.reply-form@save' => [['livewire', 'call', 'tickets.reply-form@save', 470], ['mail', 1], ['livewire', 'render', 'tickets.reply-form', 30]],
                'livewire:(batch)' => [['livewire', 'call', 'tickets.reply-form@save', 330], ['livewire', 'render', 'tickets.reply-form', 30], ['livewire', 'call', 'tickets.sidebar@$refresh', 3], ['livewire', 'render', 'tickets.sidebar', 40]],
            ],
            'queues' => ['default' => [['App\\Notifications\\TicketReplied', 260, 0.003]]],
            'jobs_per_hour' => 30,
            'backlog' => null,
            'schedule' => [['artisan tickets:close-stale', '0 3 * * *', 9_000]],
            'commands' => [['migrate', ['--force' => true]]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['monitored', 'http', 'Helpdesk', ['url' => 'https://desk.example.com/login']],
            ],
            'log_channels' => ['default'],
        ];
    }

    /**
     * An Inertia app: each page is visited, partially reloaded for its deferred props
     * and prefetched on hover, and each kind of a route is its own row. An Inertia route's
     * extra names the kind, the page component and the props a partial reload asked for.
     *
     * @return array<string, mixed>
     */
    private static function planner(): array
    {
        $inertia = fn (string $kind, string $component, array $only = []): array => ['kind' => $kind, 'component' => $component, 'only' => $only];

        return [
            'team' => 'Local Team',
            'name' => 'Planner',
            'slug' => 'planner',
            'prefix' => 'PLAN',
            'domain' => 'app.planner.example',
            'peak' => 25,
            'sample_rate' => 0.1,
            'hosts' => ['production' => ['planner-1', 'planner-2']],
            'workers' => ['production' => ['planner-1']],
            'machine' => [4, 8, 60],
            'releases' => [['1.19.0', 26.0], ['1.20.0', 12.0], ['1.20.1', 3.1]],
            'routes' => [
                ['GET', '/dashboard', 'dashboard', 6, 160, $inertia('inertia', 'Dashboard')],
                ['GET', '/dashboard', 'dashboard', 6, 430, $inertia('inertia-partial', 'Dashboard', ['chart'])],
                ['GET', '/projects', 'projects.index', 8, 180, $inertia('inertia', 'Projects/Index')],
                ['GET', '/projects/{project}', 'projects.show', 10, 220, $inertia('inertia', 'Projects/Show')],
                // The deferred timeline: the slow part of the page, loaded after it.
                ['GET', '/projects/{project}', 'projects.show', 9, 560, ['slow' => 0.01, ...$inertia('inertia-partial', 'Projects/Show', ['timeline', 'stats'])]],
                ['GET', '/projects/{project}', 'projects.show', 4, 210, $inertia('inertia-prefetch', 'Projects/Show')],
                ['GET', '/projects/{project}', 'projects.show', 1.2, 380],
                ['PUT', '/tasks/{task}', 'tasks.update', 4, 190, ['422' => 0.05, ...$inertia('inertia', 'Projects/Show')]],
                ['POST', '/login', 'login', 1, 210, ['302' => 0.9, '422' => 0.08]],
            ],
            'pages' => ['/dashboard', '/projects', '/projects/{project}'],
            'queries' => [
                '/projects/{project}' => [
                    ['select * from "projects" where "id" = ? limit 1', 'app/Http/Controllers/ProjectController.php:34', 1.1],
                    ['select * from "activities" where "project_id" = ? order by "created_at" desc limit 200', 'app/Http/Controllers/ProjectController.php:52', 160],
                ],
                '/dashboard' => [['select date_trunc(\'week\', "completed_at") as week, count(*) as done from "tasks" where "team_id" = ? group by 1', 'app/Http/Controllers/DashboardController.php:27', 120]],
            ],
            'spans' => ['/projects/{project}' => [['cache', 'project:{slug}:stats', false]]],
            'queues' => ['default' => [['App\\Jobs\\SendDigest', 1_200, 0.002]]],
            'jobs_per_hour' => 12,
            'backlog' => null,
            'schedule' => [['artisan digests:send', '0 7 * * 1-5', 14_000]],
            'commands' => [['migrate', ['--force' => true]]],
            'checks' => [
                ['reported', 'database:pgsql', 'db'],
                ['monitored', 'http', 'Planner', ['url' => 'https://app.planner.example/login']],
            ],
            'log_channels' => ['default'],
        ];
    }
}
