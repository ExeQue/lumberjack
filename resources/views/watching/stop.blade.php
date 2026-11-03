@php
    $ref = $issue->number !== null ? $issue->reference() : $issue->title;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ __('issues.watching.stop_title', ['ref' => $ref]) }}</title>
<style>
    body { margin: 0; background: #f6f7f8; color: #15181c; font-family: 'IBM Plex Sans', -apple-system, 'Segoe UI', Roboto, sans-serif; }
    main { max-width: 520px; margin: 64px auto; padding: 0 16px; }
    .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; margin-bottom: 16px; }
    .brand img { width: 28px; height: 28px; border-radius: 7px; }
    .card { background: #fff; border: 1px solid #e2e5e9; border-radius: 10px; padding: 28px 32px; }
    h1 { margin: 0 0 8px; font-size: 22px; }
    p { margin: 0 0 18px; color: #4a535c; line-height: 1.6; }
    code { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    button { background: #b86e00; color: #fff; border: 0; border-radius: 8px; padding: 12px 22px; font: inherit; font-weight: 600; cursor: pointer; }
    a { color: #b86e00; }
    @media (prefers-color-scheme: dark) {
        body { background: #0e1012; color: #e7eaee; }
        .card { background: #161a1e; border-color: #262c32; }
        p { color: #a9b1ba; }
        button { background: #f0a93b; color: #1a1206; }
        a { color: #f0a93b; }
    }
</style>
</head>
<body>
<main>
    <div class="brand"><img src="{{ asset('images/lumberjack-avatar.png') }}" alt="">Lumberjack</div>
    <div class="card">
        @if ($stopped)
            <h1>{{ __('issues.watching.stopped_title') }}</h1>
            <p>{{ __('issues.watching.stopped', ['ref' => $ref, 'title' => $issue->title]) }}</p>
            <a href="{{ route('issues.show', [$issue->project, $issue]) }}">{{ __('issues.watching.open', ['ref' => $ref]) }}</a>
        @else
            <h1>{{ __('issues.watching.stop_title', ['ref' => $ref]) }}</h1>
            <p>{{ __('issues.watching.stop_body', ['ref' => $ref, 'title' => $issue->title]) }}</p>
            <form method="post" action="{{ $action }}">
                <button type="submit">{{ __('issues.watching.stop_button') }}</button>
            </form>
        @endif
    </div>
</main>
</body>
</html>
