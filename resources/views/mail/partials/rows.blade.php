{{-- Issues, one row each, every row a link to its issue. --}}
<div style="padding-top:18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="lj-line" style="border:1px solid #e2e5e9; border-radius:8px; border-collapse:separate; font-size:14px;">
        @foreach ($rows as $row)
            <tr>
                <td class="lj-row" style="padding:10px 14px;{{ $loop->first ? '' : ' border-top:1px solid #eef0f2;' }}">
                    <a href="{{ $row['url'] }}" class="lj-ink" style="color:#15181c; text-decoration:none; display:block;">
                        @if ($row['ref'] !== null)<span class="lj-ref" style="font-family:{{ $mono }}; font-weight:600; color:#b86e00; text-decoration:underline;">{{ $row['ref'] }}</span>&nbsp;&nbsp;@endif<span class="lj-ink" style="color:#15181c;">{{ $row['title'] }}</span>
                    </a>
                </td>
                @if (($row['state'] ?? null) !== null)
                    <td align="right" class="lj-row lj-{{ $row['tone'] ?? 'gray' }}" style="padding:10px 14px; white-space:nowrap; font-size:12px; color:{{ $tones[$row['tone'] ?? 'gray'] }};{{ $loop->first ? '' : ' border-top:1px solid #eef0f2;' }}">{{ $row['state'] }}</td>
                @endif
            </tr>
        @endforeach
        @if ($more !== null)
            <tr><td colspan="2" class="lj-row lj-muted" style="padding:10px 14px; border-top:1px solid #eef0f2; color:#5f6872;">{{ $more }}</td></tr>
        @endif
    </table>
</div>
