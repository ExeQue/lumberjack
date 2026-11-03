{{-- The one primary action, solid so it survives a client that inverts colors, and a link beside it. --}}
<div style="padding-top:22px;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="lj-btn" style="background:#b86e00; border-radius:8px;">
                <a href="{{ $button['url'] }}" class="lj-btn-a" style="display:inline-block; padding:12px 22px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $button['label'] }}</a>
            </td>
            @if ($link !== null)
                <td style="padding-left:16px; font-size:14px;">
                    <a href="{{ $link['url'] }}" class="lj-link" style="color:#b86e00;">{{ $link['label'] }}</a>
                </td>
            @endif
        </tr>
    </table>
</div>
