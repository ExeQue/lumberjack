@php
    /** @var \App\Domains\Shared\Mail\MailCard $card */
    $sans = "'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $mono = "'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";
    $tones = ['red' => '#c2352a', 'amber' => '#b86e00', 'blue' => '#1f5fb8', 'green' => '#1e7a50', 'gray' => '#5f6872'];
    $tone = $card->tone !== null ? $tones[$card->tone] : null;
    $footer = $card->footer();
    $html = fn ($value) => $value instanceof \Illuminate\Contracts\Support\Htmlable ? $value->toHtml() : e((string) $value);
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $card->subject }}</title>
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; }
    a { color: #b86e00; }
    @media only screen and (max-width: 620px) {
        .lj-pad { padding: 22px 20px !important; }
        .lj-outer { padding: 20px 10px !important; }
        .lj-fact { display: block !important; width: auto !important; border-left: 0 !important; }
    }
    @media (prefers-color-scheme: dark) {
        .lj-ground { background: #0e1012 !important; }
        .lj-panel { background: #161a1e !important; border-color: #262c32 !important; }
        .lj-line { border-color: #262c32 !important; }
        .lj-row { border-color: #1f2429 !important; }
        .lj-ink { color: #e7eaee !important; }
        .lj-ink2 { color: #a9b1ba !important; }
        .lj-muted { color: #8a939c !important; }
        .lj-box { background: #0e1012 !important; border-color: #262c32 !important; color: #e7eaee !important; }
        .lj-quote { background: #1b2025 !important; border-color: #262c32 !important; color: #a9b1ba !important; }
        .lj-btn { background: #f0a93b !important; }
        .lj-btn-a { color: #1a1206 !important; }
        .lj-link, .lj-ref { color: #f0a93b !important; }
        .lj-red { color: #f2705f !important; } .lj-bg-red { background: #f2705f !important; }
        .lj-amber { color: #f0a93b !important; } .lj-bg-amber { background: #f0a93b !important; }
        .lj-blue { color: #79a8f0 !important; } .lj-bg-blue { background: #79a8f0 !important; }
        .lj-green { color: #4cc38a !important; } .lj-bg-green { background: #4cc38a !important; }
        .lj-gray { color: #8a939c !important; } .lj-bg-gray { background: #8a939c !important; }
        .lj-avatar { background: #13301f !important; color: #4cc38a !important; }
        .lj-avatar-blue { background: #14243d !important; color: #79a8f0 !important; }
    }
    /* Outlook.com dark mode marks what it recolors instead of honoring the media query. */
    [data-ogsb] .lj-ground { background: #0e1012 !important; }
    [data-ogsb] .lj-panel { background: #161a1e !important; }
    [data-ogsc] .lj-ink { color: #e7eaee !important; }
    [data-ogsc] .lj-ink2 { color: #a9b1ba !important; }
    [data-ogsc] .lj-muted { color: #8a939c !important; }
    [data-ogsb] .lj-btn { background: #f0a93b !important; }
    [data-ogsc] .lj-btn-a { color: #1a1206 !important; }
</style>
</head>
<body class="lj-ground" style="margin:0; padding:0; background:#f6f7f8; font-family:{{ $sans }}; color:#15181c;">
<div style="display:none; max-height:0; max-width:0; overflow:hidden; opacity:0; mso-hide:all; font-size:1px; line-height:1px; color:#f6f7f8;">{{ $card->preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 60) !!}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="lj-ground" style="background:#f6f7f8;">
<tr>
<td align="center" class="lj-outer" style="padding:32px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
    <tr>
        <td style="padding:0 0 16px 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td valign="middle" style="font-family:{{ $sans }};">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td valign="middle" style="width:28px; height:28px; background:#1a1d21; border-radius:7px;">
                                    <img src="{{ asset('images/lumberjack-avatar.png') }}" width="28" height="28" alt="" style="display:block; width:28px; height:28px; border:0; border-radius:7px;">
                                </td>
                                <td valign="middle" class="lj-ink" style="padding-left:10px; font-family:{{ $sans }}; font-size:15px; font-weight:700; color:#15181c;">Lumberjack</td>
                            </tr>
                        </table>
                    </td>
                    @if ($card->pill !== null)
                        <td align="right" valign="middle">
                            <span class="lj-panel lj-ink2" style="display:inline-block; font-family:{{ $mono }}; font-size:12px; color:#4a535c; border:1px solid #e2e5e9; border-radius:999px; padding:3px 10px; background:#ffffff;">{{ $card->pill }}</span>
                        </td>
                    @endif
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="lj-panel" style="background:#ffffff; border:1px solid #e2e5e9; border-radius:10px; border-collapse:separate; overflow:hidden;">
                @if ($tone !== null)
                    <tr><td class="lj-bg-{{ $card->tone }}" height="4" style="height:4px; line-height:4px; font-size:0; background:{{ $tone }}; border-radius:10px 10px 0 0;">&nbsp;</td></tr>
                @endif
                <tr>
                    <td class="lj-pad" style="padding:28px 32px; font-family:{{ $sans }}; color:#15181c;">
                        @if ($card->kind !== null)
                            <div class="lj-{{ $card->tone ?? 'gray' }}" style="font-size:12px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:{{ $tone ?? '#5f6872' }}; padding-bottom:6px;">{{ $card->kind }}</div>
                        @endif
                        <h1 class="lj-ink" style="margin:0; font-size:22px; line-height:1.3; font-weight:700; color:#15181c;">
                            @if ($card->titleUrl !== null)<a href="{{ $card->titleUrl }}" class="lj-ink" style="color:#15181c; text-decoration:none;">@endif
                            @php($ref = $card->titleRef !== null ? '<span class="'.($card->titleUrl !== null ? 'lj-ref' : 'lj-ink2').'" style="font-family:'.e($mono).'; font-weight:600; color:'.($card->titleUrl !== null ? '#b86e00; text-decoration:underline' : '#4a535c').';">'.e($card->titleRef).'</span>' : '')
                            {!! $card->titleRefLast ? e($card->title).' '.$ref : trim($ref.' '.e($card->title)) !!}
                            @if ($card->titleUrl !== null)</a>@endif
                        </h1>
                        @if ($card->lead !== null)
                            <p class="lj-ink2" style="margin:8px 0 0 0; font-size:{{ $card->tone === null ? '15px' : '14px' }}; line-height:1.6; color:#4a535c;">{!! $html($card->lead) !!}</p>
                        @endif

                        @if ($card->stat !== null)
                            <div style="padding-top:18px;"><span class="lj-{{ $card->tone ?? 'red' }}" style="font-size:28px; font-weight:700; color:{{ $tone ?? '#c2352a' }};">{{ $card->stat['value'] }}</span> <span class="lj-ink2" style="font-size:14px; color:#4a535c;">{{ $card->stat['text'] }}</span></div>
                        @endif

                        @if ($card->box !== null)
                            <div style="padding-top:18px;">
                                <div class="lj-box" style="font-family:{{ $mono }}; font-size:13px; line-height:1.6; background:#f0f2f4; border:1px solid #e2e5e9; border-radius:8px; padding:14px 16px; color:#15181c; word-break:break-word;">{{ $card->box['main'] }}@if ($card->box['muted'] !== null)<br><span class="lj-muted" style="color:#5f6872;">{{ $card->box['muted'] }}</span>@endif</div>
                            </div>
                        @endif

                        @if ($card->comment !== null)
                            @include('mail.partials.comment', ['comment' => $card->comment, 'tone' => $card->tone, 'mono' => $mono])
                        @endif

                        @if ($card->rows !== [])
                            @include('mail.partials.rows', ['rows' => $card->rows, 'more' => $card->more, 'mono' => $mono, 'tones' => $tones])
                        @endif

                        @if ($card->timeline !== [])
                            @include('mail.partials.timeline', ['timeline' => $card->timeline, 'tones' => $tones])
                        @endif

                        @if ($card->facts !== [])
                            @include('mail.partials.facts', ['facts' => $card->facts, 'mono' => $mono, 'tones' => $tones])
                        @endif

                        @if ($card->quote !== null)
                            <div style="padding-top:18px;">
                                <div class="lj-quote" style="padding:12px 16px; background:#fbfbfc; border:1px solid #e2e5e9; border-radius:8px; font-size:14px; line-height:1.6; color:#4a535c;"><strong class="lj-ink" style="color:#15181c;">{{ $card->quote['label'] }}</strong> {{ $card->quote['body'] }}</div>
                            </div>
                        @endif

                        @if ($card->button !== null)
                            @include('mail.partials.button', ['button' => $card->button, 'link' => $card->link])
                        @endif

                        @if ($card->after !== null)
                            <p class="lj-muted" style="margin:18px 0 0 0; font-size:13px; line-height:1.6; color:#5f6872;">{!! $html($card->after) !!}</p>
                        @endif

                        @if ($card->fallbackUrl !== null)
                            <div class="lj-line lj-muted" style="margin-top:18px; border-top:1px solid #e2e5e9; padding-top:14px; font-size:12px; line-height:1.6; color:#5f6872;">
                                {{ __('mail.fallback_url') }}<br>
                                <span class="lj-ink2" style="font-family:{{ $mono }}; color:#4a535c; word-break:break-all;">{{ $card->fallbackUrl }}</span>
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td class="lj-muted" style="padding:16px 0 0 0; font-family:{{ $sans }}; font-size:12px; line-height:1.6; color:#5f6872;">{!! $footer['html'] !!}</td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>
