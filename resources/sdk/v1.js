/*! Lumberjack browser SDK v1. Catches uncaught errors and unhandled rejections and measures Core Web Vitals, and sends them to Lumberjack. */
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
        vitals: data.vitals !== 'false',
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

    // Core Web Vitals, one event per page view, sent when the page is hidden or left.
    var vitals = { lcp: null, inp: null, cls: null, fcp: null, ttfb: null };
    var vitalsSent = false;

    function observe(type, handle, options) {
        var supported = PerformanceObserver.supportedEntryTypes || [];

        // An unsupported type is ignored without an error, so ask first.
        if (supported.indexOf(type) === -1) {
            return false;
        }

        try {
            new PerformanceObserver(function (list) {
                list.getEntries().forEach(handle);
            }).observe(Object.assign({ type: type, buffered: true }, options));

            return true;
        } catch {
            return false;
        }
    }

    function sendVitals() {
        if (
            vitalsSent ||
            isBot ||
            !config.vitals ||
            !config.key ||
            !config.endpoint
        ) {
            return;
        }

        var any = Object.keys(vitals).some(function (name) {
            return vitals[name] !== null;
        });

        if (!any) {
            return;
        }

        vitalsSent = true;
        queue.push({
            type: 'vital',
            route: location.pathname,
            url: location.href,
            lcp_ms: vitals.lcp,
            inp_ms: vitals.inp,
            cls: vitals.cls,
            ttfb_ms: vitals.ttfb,
            fcp_ms: vitals.fcp,
            timestamp: Date.now() / 1000,
        });
        flush();
    }

    if (config.vitals && !isBot && typeof PerformanceObserver === 'function') {
        // The browser stops reporting LCP candidates after the first input.
        observe('largest-contentful-paint', function (entry) {
            vitals.lcp = entry.startTime;
        });

        observe('paint', function (entry) {
            if (entry.name === 'first-contentful-paint') {
                vitals.fcp = entry.startTime;
            }
        });

        // INP, approximated as the slowest interaction of the page view. The first input
        // is observed too, so a page with only fast interactions still reports one.
        var interaction = function (entry) {
            if (
                (entry.interactionId || entry.entryType === 'first-input') &&
                (vitals.inp === null || entry.duration > vitals.inp)
            ) {
                vitals.inp = entry.duration;
            }
        };
        observe('event', interaction, { durationThreshold: 40 });
        observe('first-input', interaction);

        // CLS: the largest session window of shifts (at most 1 s apart, 5 s long),
        // leaving out shifts right after an input.
        var session = 0;
        var sessionStart = 0;
        var sessionLast = 0;
        var shifts = observe('layout-shift', function (entry) {
            if (entry.hadRecentInput) {
                return;
            }

            if (
                session > 0 &&
                entry.startTime - sessionLast < 1000 &&
                entry.startTime - sessionStart < 5000
            ) {
                session += entry.value;
            } else {
                session = entry.value;
                sessionStart = entry.startTime;
            }

            sessionLast = entry.startTime;
            vitals.cls = Math.max(vitals.cls || 0, session);
        });

        if (shifts && vitals.cls === null) {
            vitals.cls = 0;
        }

        var navigation =
            performance.getEntriesByType &&
            performance.getEntriesByType('navigation')[0];

        if (navigation && navigation.responseStart > 0) {
            vitals.ttfb = navigation.responseStart;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                sendVitals();
            }
        });
        window.addEventListener('pagehide', sendVitals);
    }

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
