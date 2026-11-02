{{-- Every error page (404, 403, 500, 503 ...) in Lumberjack's look; Laravel's own pages extend this. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') - {{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <style>
            :root { color-scheme: dark; }
            * { box-sizing: border-box; margin: 0; }
            body {
                min-height: 100vh; display: grid; place-items: center; padding: 24px;
                background: #0e1012; color: #f3f1ec;
                font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            }
            main { display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center; max-width: 28rem; }
            .code { font: 600 13px ui-monospace, 'JetBrains Mono', monospace; color: #f0a93b; letter-spacing: .08em; }
            h1 { font-size: 22px; font-weight: 600; letter-spacing: -.01em; }
            a { color: #f0a93b; text-decoration: none; font-size: 14px; }
            a:hover, a:focus-visible { text-decoration: underline; }
        </style>
    </head>
    <body>
        <main>
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f0a93b" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><circle cx="12.5" cy="12.5" r="6.5"/><circle cx="13" cy="13" r="3"/><path d="M12 2l1 5"/>
            </svg>
            <div class="code">@yield('code')</div>
            <h1>@yield('message')</h1>
            <a href="{{ url('/') }}">Back to Lumberjack</a>
        </main>
    </body>
</html>
