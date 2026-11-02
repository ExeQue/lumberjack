/*! Lumberjack browser SDK v1. Catches uncaught errors and unhandled rejections and sends them to Lumberjack. */
(function () {
    'use strict';

    if (window.Lumberjack && window.Lumberjack.version) {
        return;
    }

    var MAX_PER_PAGE = 100;
    var MAX_PER_BATCH = 50;
    var MAX_SAME = 5;

    // Crawlers, link previews and automated browsers: their errors are noise.
    var BOTS =
        /bot\b|bot\/|crawl|spider|slurp|headless|lighthouse|pagespeed|prerender|phantom|puppeteer|playwright|selenium|facebookexternalhit|embedly|preview/i;
    // Errors with nothing to act on.
    var NOISE = [
        /^Script error\.?$/,
        /ResizeObserver loop/,
        /^Non-Error promise rejection captured/,
        /^Uncaught \(in promise\)$/,
    ];
    var EXTENSION = /^(chrome|moz|safari(-web)?|ms-browser)-extension:/;

    var script = document.currentScript;
    var data = (script && script.dataset) || {};
    var config = {
        key: data.key || null,
        endpoint:
            data.endpoint ||
            (script && script.src
                ? new URL('/api/ingest/browser', script.src).href
                : null),
        environment: data.environment || null,
        release: data.release || null,
        user: data.user || null,
        trace: data.trace || null,
        announce: data.announce === 'true',
        ignore: [],
    };
    var isBot = !!navigator.webdriver || BOTS.test(navigator.userAgent || '');

    var queue = [];
    var sent = 0;
    var seen = {};
    var timer = null;

    function parseStack(stack) {
        var frames = [];
        String(stack || '')
            .split('\n')
            .forEach(function (line) {
                // Chrome / Edge: "    at fn (https://x/app.js:10:5)" or "    at https://x/app.js:10:5"
                var m =
                    line.match(/^\s*at (?:(.+?) \()?(.+?):(\d+):(\d+)\)?$/) ||
                    // Firefox / Safari: "fn@https://x/app.js:10:5"
                    line.match(/^\s*(?:(.*?)@)?(.+?):(\d+):(\d+)$/);

                if (!m) {
                    return;
                }

                var file = m[2];
                frames.push({
                    file: file,
                    line: parseInt(m[3], 10),
                    column: parseInt(m[4], 10),
                    function: m[1] || null,
                    in_app: file.indexOf(location.origin) === 0,
                });
            });

        return frames.slice(0, 100);
    }

    function normalize(error) {
        if (error instanceof Error) {
            return {
                name: error.name || 'Error',
                message: error.message || '',
                stack: error.stack || '',
            };
        }

        var message;
        try {
            message = typeof error === 'string' ? error : JSON.stringify(error);
        } catch {
            message = String(error);
        }

        return {
            name: 'Error',
            message: message || 'Unknown error',
            stack: '',
        };
    }

    function capture(error, context, location_) {
        if (isBot || !config.key || !config.endpoint || sent >= MAX_PER_PAGE) {
            return;
        }

        var e = normalize(error);
        var ignored = NOISE.concat(config.ignore).some(function (pattern) {
            return pattern instanceof RegExp
                ? pattern.test(e.message)
                : e.message.indexOf(String(pattern)) !== -1;
        });

        if (ignored) {
            return;
        }

        var fingerprint = e.name + ':' + e.message;
        seen[fingerprint] = (seen[fingerprint] || 0) + 1;

        if (seen[fingerprint] > MAX_SAME) {
            return;
        }

        var frames = parseStack(e.stack);

        // Thrown by a browser extension, not by the page.
        if (
            frames.length > 0 &&
            frames.every(function (frame) {
                return EXTENSION.test(frame.file);
            })
        ) {
            return;
        }

        var top = frames[0] || location_ || {};

        sent++;
        queue.push({
            type: 'exception',
            class: e.name,
            message: e.message,
            file: top.file || null,
            line: top.line || null,
            stacktrace: frames,
            trace_id: config.trace,
            timestamp: Date.now() / 1000,
            context: Object.assign(
                {
                    request: {
                        method: 'GET',
                        url: location.href,
                        route: location.pathname,
                    },
                    user: config.user ? { id: config.user } : undefined,
                    browser: {
                        user_agent: navigator.userAgent,
                        language: navigator.language,
                    },
                },
                context || {},
            ),
        });

        schedule();
    }

    function schedule() {
        if (timer === null) {
            timer = setTimeout(flush, 1000);
        }
    }

    function flush() {
        timer = null;

        while (queue.length) {
            var body = JSON.stringify({
                v: 1,
                environment: config.environment,
                release: config.release,
                events: queue.splice(0, MAX_PER_BATCH),
            });
            var url =
                config.endpoint + '?key=' + encodeURIComponent(config.key);

            // fetch with keepalive survives a page unload like sendBeacon, but without cookies
            // (a beacon always sends them, and some browsers and extensions drop beacons to
            // another site). A string body is text/plain, so there is no CORS preflight.
            if (typeof fetch === 'function') {
                fetch(url, {
                    method: 'POST',
                    body: body,
                    keepalive: true,
                    mode: 'cors',
                    credentials: 'omit',
                }).catch(function () {});
            } else if (navigator.sendBeacon) {
                navigator.sendBeacon(url, body);
            }
        }
    }

    window.addEventListener('error', function (event) {
        capture(event.error || event.message, null, {
            file: event.filename,
            line: event.lineno,
        });
    });

    window.addEventListener('unhandledrejection', function (event) {
        capture(event.reason, { unhandled_rejection: true });
    });

    window.addEventListener('pagehide', flush);

    if (config.announce && window.console && console.info) {
        if (config.key && config.endpoint) {
            console.info(
                '%cLumberjack%c v1.0.0 catches errors on this page' +
                    (config.environment
                        ? ' (' +
                          config.environment +
                          (config.release ? ', ' + config.release : '') +
                          ')'
                        : '') +
                    '.',
                'background:#d97706;color:#fff;border-radius:3px;padding:1px 5px;font-weight:600',
                '',
            );
        } else {
            console.warn(
                'Lumberjack: no public key (data-key), so errors are not sent.',
            );
        }
    }

    window.Lumberjack = {
        version: '1.0.0',
        init: function (options) {
            Object.assign(config, options || {});
        },
        setUser: function (id) {
            config.user = id === null || id === undefined ? null : String(id);
        },
        captureException: function (error, context) {
            capture(error, context);
        },
        flush: flush,
    };
})();
