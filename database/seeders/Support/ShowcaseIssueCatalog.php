<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * The showcase issues per project: what broke, where, how often and what the team did
 * about it. Each is an exception (or a log line, or a browser error) with the frames
 * of the app that threw it; the framework's frames are added by the kind of work it
 * happened in. Times are in days before now.
 *
 * Keys: title, message, source (server, browser, log), frames (top first: file, line,
 * function, class), kind (http, queue, console, browser, log), route, daily (occurrences
 * a day), from and until (days ago), users (share with a signed-in user), state (open,
 * in_progress, resolved, ignored, regressed, escalating, returned, bad_release), and
 * optionally job / task / missed / command (its occurrences are those runs' failures;
 * a resolved job's runs stop failing at 'until'),
 * labels, assignee, notes ([author email or MCP agent name, body], <@email> mentions),
 * watchers, repo (an issue in the repository), ignore (a condition).
 */
final class ShowcaseIssueCatalog
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function issues(): array
    {
        return [
            'storefront' => self::storefront(),
            'orders-api' => self::ordersApi(),
            'marketing-site' => self::marketing(),
            'fulfillment-worker' => self::worker(),
            'acme-booking' => self::booking(),
            'customer-portal' => self::portal(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function storefront(): array
    {
        return [
            [
                'title' => 'ErrorException',
                'message' => 'Undefined array key "shipping_method"',
                'frames' => [
                    ['app/Actions/PlaceOrderAction.php', 88, 'execute', 'App\\Actions\\PlaceOrderAction', [
                        '        $order = $this->orders->create($cart, $customer);',
                        '        // v2.16.0: the shipping step moved into the payment form.',
                        '        $method = $request[\'shipping_method\'];',
                        '        $order->shipping()->create($this->rates->quote($cart, $method));',
                        '',
                    ]],
                    ['app/Http/Controllers/CheckoutController.php', 54, 'store', 'App\\Http\\Controllers\\CheckoutController'],
                ],
                'kind' => 'http', 'route' => ['POST', '/checkout'],
                'state' => 'bad_release', 'users' => 0.95,
                'labels' => ['checkout', 'p1', 'customer-facing'], 'assignee' => 'maja@lumberjack',
                'repo' => 'https://github.com/example/storefront/issues/412',
                'notes' => [
                    ['oskar@lumberjack', 'Started the minute v2.16.0 went out, every checkout without a saved address fails. <@maja@lumberjack> can we roll back?'],
                    ['maja@lumberjack', 'Rolled back to v2.15.1. The new payment form does not post shipping_method when the address is new; fix is in v2.16.1.'],
                    ['triage-bot', 'Triage: 412 occurrences in 6 hours, all on POST /checkout, all on release v2.16.0. Stopped with the rollback. Resolved by v2.16.1, no occurrences since.'],
                ],
                'watchers' => ['admin@lumberjack', 'oskar@lumberjack'],
            ],
            [
                'title' => 'RedisException',
                'message' => 'read error on connection to redis-cache.internal:6379',
                'frames' => [
                    ['app/Http/Middleware/TrackRecentlyViewed.php', 31, 'handle', 'App\\Http\\Middleware\\TrackRecentlyViewed', [
                        '    public function handle(Request $request, Closure $next): Response',
                        '    {',
                        '        Redis::lpush(\'viewed:\'.$request->session()->getId(), $request->route(\'slug\'));',
                        '',
                        '        return $next($request);',
                    ]],
                ],
                'kind' => 'http', 'route' => ['GET', '/products/{slug}'],
                'state' => 'escalating', 'from' => 0.03, 'daily' => 0, 'users' => 0.4,
                'labels' => ['infra'],
                'watchers' => ['admin@lumberjack'],
            ],
            [
                'title' => 'Illuminate\\Database\\QueryException',
                'message' => 'SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected DETAIL: Process 81723 waits for ShareLock on transaction 4419021; blocked by process 81690. (Connection: pgsql, SQL: update "product_variants" set "stock" = "stock" - 1 where "id" = 48213)',
                'frames' => [
                    ['app/Actions/PlaceOrderAction.php', 71, 'reserveStock', 'App\\Actions\\PlaceOrderAction'],
                    ['app/Actions/PlaceOrderAction.php', 49, 'execute', 'App\\Actions\\PlaceOrderAction'],
                    ['app/Http/Controllers/CheckoutController.php', 54, 'store', 'App\\Http\\Controllers\\CheckoutController'],
                ],
                'kind' => 'http', 'route' => ['POST', '/checkout'],
                'state' => 'in_progress', 'from' => 12.3, 'daily' => 4, 'users' => 0.9,
                'labels' => ['checkout', 'flaky'], 'assignee' => 'oskar@lumberjack',
                'notes' => [
                    ['triage-bot', 'Triage: two checkouts for the same variant lock the rows in a different order. Locking variants sorted by id in PlaceOrderAction::reserveStock should stop it. About 4 a day, at the evening peak.'],
                    ['oskar@lumberjack', 'Taking this one. <@ida@lumberjack> do you remember why we lock per line instead of per order?'],
                ],
                'watchers' => ['ida@lumberjack'],
            ],
            [
                'title' => 'TypeError',
                'message' => 'Cannot read properties of undefined (reading \'price\')',
                'source' => 'browser',
                'frames' => [
                    ['https://shop.example.com/build/assets/VariantPicker-4f9c2a1b.js', 1, 'selectVariant', null],
                    ['https://shop.example.com/build/assets/app-81d3e0c7.js', 2, 'onClick', null],
                ],
                'kind' => 'browser', 'route' => ['GET', '/products/{slug}'],
                'state' => 'open', 'from' => 0.45, 'daily' => 60, 'users' => 0.7,
                'labels' => ['customer-facing', 'needs-repro'],
            ],
            [
                'title' => 'Illuminate\\Http\\Client\\ConnectionException',
                'message' => 'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for http://meilisearch.internal:7700/indexes/products/search',
                'frames' => [
                    ['app/Search/ProductSearch.php', 63, 'search', 'App\\Search\\ProductSearch'],
                    ['app/Http/Controllers/SearchController.php', 24, '__invoke', 'App\\Http\\Controllers\\SearchController'],
                ],
                'kind' => 'http', 'route' => ['GET', '/search'],
                'state' => 'regressed', 'from' => 18, 'resolved' => 8, 'back' => 0.9, 'daily' => 9, 'users' => 0.5,
                'labels' => ['infra'], 'assignee' => 'ida@lumberjack',
                'notes' => [
                    ['ida@lumberjack', 'Raised the search timeout to 3s and added a replica; resolving.'],
                    ['oskar@lumberjack', 'Back since v2.16.2, only on long queries. <@ida@lumberjack> did the replica survive the infra change on Monday?'],
                ],
            ],
            [
                'title' => 'RuntimeException',
                'message' => 'Stock reservation failed for variant 48213: warehouse API answered 409 Conflict',
                'frames' => [
                    ['app/Jobs/SyncInventory.php', 57, 'handle', 'App\\Jobs\\SyncInventory'],
                    ['app/Inventory/WarehouseClient.php', 112, 'reserve', 'App\\Inventory\\WarehouseClient'],
                ],
                'kind' => 'queue', 'job' => 'App\\Jobs\\SyncInventory',
                'state' => 'open', 'users' => 0,
                'labels' => ['infra'],
            ],
            [
                'title' => 'Stripe\\Exception\\CardException',
                'message' => 'Your card was declined.',
                'frames' => [
                    ['app/Http/Controllers/CheckoutPaymentController.php', 52, 'store', 'App\\Http\\Controllers\\CheckoutPaymentController'],
                    ['app/Payments/StripeGateway.php', 88, 'charge', 'App\\Payments\\StripeGateway'],
                ],
                'kind' => 'http', 'route' => ['POST', '/checkout/payment'],
                'state' => 'ignored', 'ignore' => 'forever', 'from' => 29, 'daily' => 22, 'users' => 1.0,
                'labels' => ['payments'],
                'notes' => [['maja@lumberjack', 'Declined cards are the customer\'s bank saying no, not a bug. Ignoring; the payments dashboard tracks the rate.']],
            ],
            [
                'title' => 'Stripe webhook signature mismatch for event evt_1Q8zKX2eZvKYlo2C',
                'message' => 'Stripe webhook signature mismatch for event evt_1Q8zKX2eZvKYlo2C',
                'source' => 'log', 'log_channel' => 'payments',
                'kind' => 'log', 'route' => ['POST', '/webhooks/stripe'],
                'state' => 'ignored', 'ignore' => '7d', 'from' => 9, 'daily' => 3, 'users' => 0,
                'labels' => ['payments'],
            ],
            [
                'title' => 'Symfony\\Component\\Mailer\\Exception\\TransportException',
                'message' => 'Connection to "smtp.postmarkapp.com:587" has been closed unexpectedly.',
                'frames' => [['app/Mail/OrderConfirmation.php', 41, 'build', 'App\\Mail\\OrderConfirmation']],
                'kind' => 'queue', 'job' => 'App\\Mail\\OrderConfirmation',
                'state' => 'resolved', 'until' => 3, 'users' => 0,
            ],
            [
                'title' => 'App\\Exceptions\\CouponExpiredException',
                'message' => 'Coupon SPRING25 expired on 2026-10-31',
                'frames' => [
                    ['app/Cart/ApplyCoupon.php', 37, 'execute', 'App\\Cart\\ApplyCoupon'],
                    ['app/Http/Controllers/CartController.php', 61, 'coupon', 'App\\Http\\Controllers\\CartController'],
                ],
                'kind' => 'http', 'route' => ['POST', '/cart/items'],
                'state' => 'returned', 'from' => 6, 'ignored' => 4, 'back' => 0.6, 'daily' => 14, 'users' => 1.0,
                'labels' => ['checkout'],
            ],
            ['title' => 'Scheduled task failed: artisan reports:daily-sales', 'message' => 'Exited with code 1.', 'kind' => 'console', 'task' => 'artisan reports:daily-sales', 'state' => 'open', 'users' => 0],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function ordersApi(): array
    {
        return [
            [
                'title' => 'Illuminate\\Http\\Client\\RequestException',
                'message' => 'HTTP request returned status code 503: <html><head><title>503 Service Temporarily Unavailable</title></head> (truncated...)',
                'frames' => [
                    ['app/Jobs/DeliverWebhook.php', 46, 'handle', 'App\\Jobs\\DeliverWebhook'],
                    ['app/Webhooks/Signer.php', 29, 'post', 'App\\Webhooks\\Signer'],
                ],
                'kind' => 'queue', 'job' => 'App\\Jobs\\DeliverWebhook',
                'state' => 'in_progress', 'users' => 0,
                'labels' => ['customer-facing'], 'assignee' => 'ida@lumberjack',
                'notes' => [['ida@lumberjack', 'Two customer endpoints answer 503 at night. Retries work; adding exponential backoff so we stop hammering them.']],
            ],
            [
                'title' => 'ErrorException',
                'message' => 'Attempt to read property "currency" on null',
                'frames' => [
                    ['app/Http/Resources/OrderResource.php', 44, 'toArray', 'App\\Http\\Resources\\OrderResource', [
                        '            \'total\' => [',
                        '                \'amount\' => $this->total,',
                        '                \'currency\' => $this->account->billing->currency,',
                        '            ],',
                        '            \'lines\' => OrderLineResource::collection($this->whenLoaded(\'lines\')),',
                    ]],
                    ['app/Http/Controllers/Api/OrderController.php', 41, 'show', 'App\\Http\\Controllers\\Api\\OrderController'],
                ],
                'kind' => 'http', 'route' => ['GET', '/api/v1/orders/{order}'],
                'state' => 'open', 'from' => 0.12, 'daily' => 90, 'users' => 1.0,
                'notes' => [['triage-bot', 'Triage: only accounts created after 3.11.1 have no billing profile yet. <@admin@lumberjack> the signup flow should create one.']],
                'watchers' => ['ida@lumberjack'],
            ],
            [
                'title' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
                'message' => 'App\\Jobs\\BuildOrderExport has been attempted too many times.',
                'frames' => [['app/Jobs/BuildOrderExport.php', 38, 'handle', 'App\\Jobs\\BuildOrderExport']],
                'kind' => 'queue', 'job' => 'App\\Jobs\\BuildOrderExport',
                'state' => 'open', 'users' => 0, 'labels' => ['infra'],
            ],
            [
                'title' => 'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
                'message' => 'No query results for model [App\\Models\\Order] 98231',
                'frames' => [['app/Http/Controllers/Api/OrderController.php', 38, 'show', 'App\\Http\\Controllers\\Api\\OrderController']],
                'kind' => 'http', 'route' => ['PATCH', '/api/v1/orders/{order}'],
                'state' => 'ignored', 'ignore' => 'escalation', 'from' => 25, 'daily' => 6, 'users' => 1.0,
            ],
            [
                'title' => 'League\\OAuth2\\Server\\Exception\\OAuthServerException',
                'message' => 'The provided authorization grant (e.g., authorization code, resource owner credentials) or refresh token is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client.',
                'frames' => [['vendor/laravel/passport/src/Http/Controllers/AccessTokenController.php', 42, 'issueToken', 'Laravel\\Passport\\Http\\Controllers\\AccessTokenController']],
                'kind' => 'http', 'route' => ['POST', '/oauth/token'],
                'state' => 'ignored', 'ignore' => 'count', 'from' => 20, 'daily' => 11, 'users' => 0,
            ],
            [
                'title' => 'Illuminate\\Database\\UniqueConstraintViolationException',
                'message' => 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "orders_account_id_reference_unique" (Connection: pgsql, SQL: insert into "orders" ("account_id", "reference") values (1042, PO-20931))',
                'frames' => [
                    ['app/Actions/CreateOrderAction.php', 38, 'execute', 'App\\Actions\\CreateOrderAction'],
                    ['app/Http/Controllers/Api/OrderController.php', 57, 'store', 'App\\Http\\Controllers\\Api\\OrderController'],
                ],
                'kind' => 'http', 'route' => ['POST', '/api/v1/orders'],
                'state' => 'resolved', 'from' => 14, 'until' => 9.5, 'daily' => 5, 'users' => 1.0,
                'notes' => [['maja@lumberjack', 'Clients retried POSTs on timeout. Idempotency keys shipped in 3.10.1.']],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function marketing(): array
    {
        return [
            [
                'title' => 'ReferenceError',
                'message' => 'gtag is not defined',
                'source' => 'browser',
                'frames' => [['https://www.example.com/build/assets/consent-2b7e91aa.js', 1, 'trackSignup', null]],
                'kind' => 'browser', 'route' => ['GET', '/pricing'],
                'state' => 'open', 'from' => 10, 'daily' => 25, 'users' => 0,
                'labels' => ['flaky'],
                'notes' => [['oskar@lumberjack', 'Only when the cookie banner is declined: the script still calls gtag. Low priority.']],
            ],
            [
                'title' => 'Symfony\\Component\\Mailer\\Exception\\TransportException',
                'message' => 'Expected response code "250" but got code "550", with message "550 5.7.1 Relaying denied".',
                'frames' => [['app/Http/Controllers/ContactController.php', 33, 'store', 'App\\Http\\Controllers\\ContactController']],
                'kind' => 'http', 'route' => ['POST', '/contact'],
                'state' => 'resolved', 'from' => 16, 'until' => 15.2, 'daily' => 8, 'users' => 0,
            ],
            ['title' => 'Scheduled task missed: artisan blog:publish-scheduled', 'message' => 'Due, but the scheduler never ran it.', 'kind' => 'console', 'missed' => 'artisan blog:publish-scheduled', 'state' => 'open', 'users' => 0],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function worker(): array
    {
        return [
            [
                'title' => 'GuzzleHttp\\Exception\\ConnectException',
                'message' => 'cURL error 7: Failed to connect to labels.carrier.example port 443 after 10002 ms: Timeout was reached',
                'frames' => [
                    ['app/Carriers/LabelClient.php', 74, 'create', 'App\\Carriers\\LabelClient'],
                    ['app/Jobs/PrintShippingLabel.php', 39, 'handle', 'App\\Jobs\\PrintShippingLabel'],
                ],
                'kind' => 'queue', 'job' => 'App\\Jobs\\PrintShippingLabel',
                'state' => 'resolved', 'until' => 1.75, 'users' => 0,
                'labels' => ['infra'], 'assignee' => 'oskar@lumberjack',
                'notes' => [['oskar@lumberjack', 'Carrier had an outage, labels queue backed up to ~4.6k. Drained once they recovered; added a circuit breaker.']],
            ],
            [
                'title' => 'Illuminate\\Queue\\TimeoutExceededException',
                'message' => 'App\\Jobs\\ImportSupplierFeed has timed out.',
                'frames' => [
                    ['app/Suppliers/FeedParser.php', 128, 'rows', 'App\\Suppliers\\FeedParser'],
                    ['app/Jobs/ImportSupplierFeed.php', 51, 'handle', 'App\\Jobs\\ImportSupplierFeed'],
                ],
                'kind' => 'queue', 'job' => 'App\\Jobs\\ImportSupplierFeed',
                'state' => 'in_progress', 'users' => 0, 'assignee' => 'admin@lumberjack',
                'notes' => [['triage-bot', 'Triage: the nordic-wood feed grew to 180 MB; the parser loads it whole. Streaming it with a LazyCollection keeps it under the 900 s timeout.']],
            ],
            [
                'title' => 'App\\Exceptions\\InsufficientStockException',
                'message' => 'Not enough stock for SKU OAK-LAMP-02 (needed 3, have 1)',
                'frames' => [['app/Jobs/AllocateStock.php', 62, 'handle', 'App\\Jobs\\AllocateStock']],
                'kind' => 'queue', 'job' => 'App\\Jobs\\AllocateStock',
                'state' => 'open', 'users' => 0, 'labels' => ['customer-facing'],
            ],
            ['title' => 'Scheduled task failed: artisan shipments:track', 'message' => 'Exited with code 1.', 'kind' => 'console', 'task' => 'artisan shipments:track', 'state' => 'open', 'users' => 0],
            ['title' => 'Scheduled task missed: artisan warehouse:reconcile', 'message' => 'Due, but the scheduler never ran it.', 'kind' => 'console', 'missed' => 'artisan warehouse:reconcile', 'state' => 'open', 'users' => 0],
            ['title' => 'Command failed: queue:retry', 'message' => 'Exited with code 1.', 'kind' => 'console', 'command' => 'queue:retry', 'state' => 'open', 'users' => 0],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function booking(): array
    {
        return [
            [
                'title' => 'Illuminate\\Database\\UniqueConstraintViolationException',
                'message' => 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'14-2026-11-07\' for key \'bookings_room_id_date_unique\'',
                'frames' => [['app/Http/Controllers/BookingController.php', 47, 'store', 'App\\Http\\Controllers\\BookingController']],
                'kind' => 'http', 'route' => ['POST', '/bookings'],
                'state' => 'open', 'from' => 5, 'daily' => 2, 'users' => 1.0,
            ],
            [
                'title' => 'TypeError',
                'message' => 'date.toLocaleDateString is not a function',
                'source' => 'browser',
                'frames' => [['https://booking.acme.example/js/calendar.js', 212, 'renderDay', null]],
                'kind' => 'browser', 'route' => ['GET', '/rooms/{room}'],
                'state' => 'resolved', 'from' => 13, 'until' => 6.6, 'daily' => 12, 'users' => 0.3,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function portal(): array
    {
        return [
            [
                'title' => 'SoapFault',
                'message' => 'Could not connect to host',
                'frames' => [
                    ['app/Erp/InvoiceClient.php', 58, 'fetchSince', 'App\\Erp\\InvoiceClient'],
                    ['app/Jobs/SyncInvoicesFromErp.php', 33, 'handle', 'App\\Jobs\\SyncInvoicesFromErp'],
                ],
                'kind' => 'queue', 'job' => 'App\\Jobs\\SyncInvoicesFromErp',
                'state' => 'open', 'users' => 0, 'assignee' => 'emil@lumberjack',
            ],
            [
                'title' => 'Symfony\\Component\\Process\\Exception\\ProcessTimedOutException',
                'message' => 'The process "chromium --headless --print-to-pdf" exceeded the timeout of 60 seconds.',
                'frames' => [
                    ['app/Pdf/InvoicePdf.php', 41, 'render', 'App\\Pdf\\InvoicePdf'],
                    ['app/Http/Controllers/InvoicePdfController.php', 22, '__invoke', 'App\\Http\\Controllers\\InvoicePdfController'],
                ],
                'kind' => 'http', 'route' => ['GET', '/invoices/{invoice}/pdf'],
                'state' => 'open', 'from' => 8, 'daily' => 3, 'users' => 1.0, 'labels' => ['customer-facing'],
            ],
        ];
    }
}
