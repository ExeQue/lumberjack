{{-- What happened, oldest first: who did what at what time, and what they wrote. --}}
<div style="padding-top:18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="lj-line" style="border:1px solid #e2e5e9; border-radius:8px; border-collapse:separate;">
        @foreach ($timeline as $event)
            <tr>
                <td valign="top" width="8" class="lj-row" style="width:8px; padding:12px 0 12px 14px;{{ $loop->first ? '' : ' border-top:1px solid #eef0f2;' }}">
                    <div class="lj-bg-{{ $event['tone'] }}" style="width:8px; height:8px; margin-top:7px; border-radius:4px; font-size:0; line-height:0; background:{{ $tones[$event['tone']] }};">&nbsp;</div>
                </td>
                <td valign="top" class="lj-row" style="padding:12px 14px 12px 12px;{{ $loop->first ? '' : ' border-top:1px solid #eef0f2;' }}">
                    <div class="lj-ink" style="font-size:14px; line-height:1.5; color:#15181c;"><strong>{{ $event['who'] }}</strong> {{ $event['what'] }} <span class="lj-muted" style="color:#5f6872; font-size:12px;">{{ $event['at'] }}</span></div>
                    @if ($event['body'] !== null)
                        <div class="lj-box" style="margin-top:4px; font-size:14px; line-height:1.6; color:#4a535c; background:#f0f2f4; border:1px solid #e2e5e9; border-radius:6px; padding:8px 10px; white-space:pre-line; word-break:break-word;">{{ $event['body'] }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
</div>
