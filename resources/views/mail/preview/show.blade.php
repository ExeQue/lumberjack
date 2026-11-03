<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $subject }}</title>
<style>
    body { font-family: system-ui, sans-serif; margin: 0; color: #15181c; background: #e9ecef; }
    header { padding: 16px 24px; background: #fff; border-bottom: 1px solid #e2e5e9; }
    header h1 { font-size: 16px; margin: 0 0 4px; }
    header p { margin: 2px 0; font-size: 13px; color: #4a535c; }
    nav { padding: 8px 24px; font-size: 13px; background: #fff; border-bottom: 1px solid #e2e5e9; }
    nav a { color: #b86e00; margin-right: 10px; }
    nav a.current { font-weight: 700; color: #15181c; }
    main { display: flex; gap: 16px; padding: 16px; flex-wrap: wrap; }
    section { flex: 1 1 640px; }
    section h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: #5f6872; margin: 0 0 6px; }
    iframe { width: 100%; height: 1000px; border: 1px solid #ccd1d6; background: #fff; }
    pre { white-space: pre-wrap; background: #fff; border: 1px solid #ccd1d6; padding: 16px; font-size: 13px; }
</style></head>
<body>
<header>
    <h1>{{ $subject }}</h1>
    <p>From {{ collect($from)->filter()->reverse()->implode(' <') }}{{ count(array_filter($from)) > 1 ? '>' : '' }}</p>
    <p>Preheader: {{ $preheader }}</p>
</header>
<nav>
    <a href="{{ route('dev.mail.index') }}">All mails</a>
    @foreach ($mails as $key => $label)
        <a href="{{ route('dev.mail.show', $key) }}" @class(['current' => $key === $name])>{{ $label }}</a>
    @endforeach
</nav>
<main>
    <section><h2>Light</h2><iframe src="{{ route('dev.mail.frame', [$name, 'light']) }}" title="Light"></iframe></section>
    <section><h2>Dark</h2><iframe src="{{ route('dev.mail.frame', [$name, 'dark']) }}" title="Dark"></iframe></section>
    <section><h2>Plain text</h2><pre>{{ $text }}</pre></section>
</main>
</body>
</html>
