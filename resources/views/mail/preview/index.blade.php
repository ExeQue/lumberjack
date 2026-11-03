<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Mail preview</title>
<style>body{font-family:system-ui,sans-serif;margin:40px;color:#15181c;background:#f6f7f8}a{color:#b86e00}li{margin:6px 0}</style></head>
<body>
<h1>Every mail Lumberjack sends</h1>
<p>Sample data, nothing is sent. Local only.</p>
<ul>
    @foreach ($mails as $key => $label)
        <li><a href="{{ route('dev.mail.show', $key) }}">{{ $label }}</a> <code>{{ $key }}</code></li>
    @endforeach
</ul>
</body>
</html>
