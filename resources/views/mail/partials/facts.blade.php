{{-- Facts two to a row; an odd last one takes the whole row. --}}
@php
    $cells = [];
    foreach ($facts as $label => $fact) {
        $cells[] = ['label' => $label, ...(is_array($fact) ? $fact : ['value' => $fact])];
    }
    $pairs = array_chunk($cells, 2);
@endphp
<div style="padding-top:18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="lj-line" style="border:1px solid #e2e5e9; border-radius:8px; border-collapse:separate; font-size:14px;">
        @foreach ($pairs as $pair)
            <tr>
                @foreach ($pair as $cell)
                    <td valign="top" @if (count($pair) === 1) colspan="2" @endif width="{{ count($pair) === 1 ? '100%' : '50%' }}" class="lj-fact lj-line" style="padding:10px 14px;{{ $loop->parent->last ? '' : ' border-bottom:1px solid #e2e5e9;' }}{{ $loop->first ? '' : ' border-left:1px solid #e2e5e9;' }}">
                        <div class="lj-muted" style="font-size:12px; color:#5f6872;">{{ $cell['label'] }}</div>
                        <div class="{{ ($cell['tone'] ?? null) !== null ? 'lj-'.$cell['tone'] : 'lj-ink' }}" style="color:{{ ($cell['tone'] ?? null) !== null ? $tones[$cell['tone']] : '#15181c' }};{{ ($cell['mono'] ?? false) ? ' font-family:'.$mono.';' : '' }}{{ ($cell['tone'] ?? null) !== null ? ' font-weight:600;' : '' }}">{{ $cell['value'] }}</div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
</div>
