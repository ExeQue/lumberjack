{{-- Someone's words: an avatar with their initials, who did what, then what they wrote. --}}
<div style="padding-top:18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td valign="top" width="36" style="width:36px;">
                <div class="{{ $tone === 'green' ? 'lj-avatar' : 'lj-avatar-blue' }}" style="width:36px; height:36px; line-height:36px; border-radius:18px; text-align:center; font-size:13px; font-weight:700; background:{{ $tone === 'green' ? '#e6f6ee' : '#e7effb' }}; color:{{ $tone === 'green' ? '#1e7a50' : '#1f5fb8' }};">{{ $comment['initials'] }}</div>
            </td>
            <td valign="top" style="padding-left:12px;">
                <div class="lj-muted" style="font-size:13px; line-height:1.5; color:#5f6872; padding-bottom:4px;"><strong class="lj-ink" style="color:#15181c;">{{ $comment['name'] }}</strong> {{ $comment['what'] }}</div>
                <div class="lj-box" style="font-size:15px; line-height:1.6; background:#f0f2f4; border:1px solid #e2e5e9; border-radius:8px; padding:12px 14px; color:#15181c; white-space:pre-line; word-break:break-word;">{{ $comment['body'] }}</div>
            </td>
        </tr>
    </table>
</div>
