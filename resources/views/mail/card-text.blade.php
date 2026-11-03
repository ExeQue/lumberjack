@php
    /** @var \App\Domains\Shared\Mail\MailCard $card */

    $lines = [];
    if ($card->kind !== null) { $lines[] = mb_strtoupper($card->kind); }
    $lines[] = $card->titleRefLast ? $card->title.' '.$card->titleRef : trim(($card->titleRef !== null ? $card->titleRef.' ' : '').$card->title);
    if ($card->lead !== null) { $lines[] = \App\Domains\Shared\Mail\MailCard::plain($card->lead); }
    $lines[] = '';
    if ($card->stat !== null) { $lines[] = $card->stat['value'].' '.$card->stat['text']; $lines[] = ''; }
    if ($card->box !== null) { $lines[] = $card->box['main']; if ($card->box['muted'] !== null) { $lines[] = $card->box['muted']; } $lines[] = ''; }
    if ($card->comment !== null) { $lines[] = $card->comment['name'].' '.$card->comment['what'].':'; foreach (explode("\n", $card->comment['body']) as $l) { $lines[] = '> '.$l; } $lines[] = ''; }
    foreach ($card->rows as $row) { $lines[] = '- '.trim(($row['ref'] !== null ? $row['ref'].' ' : '').$row['title']).(($row['state'] ?? null) !== null ? ' ('.$row['state'].')' : '').': '.$row['url']; }
    if ($card->more !== null) { $lines[] = '  '.$card->more; }
    if ($card->rows !== []) { $lines[] = ''; }
    foreach ($card->timeline as $event) { $lines[] = $event['at'].'  '.$event['who'].' '.$event['what']; if ($event['body'] !== null) { foreach (explode("\n", $event['body']) as $l) { $lines[] = '       > '.$l; } } }
    if ($card->timeline !== []) { $lines[] = ''; }
    foreach ($card->facts as $label => $fact) { $lines[] = $label.': '.(is_array($fact) ? $fact['value'] : $fact); }
    if ($card->facts !== []) { $lines[] = ''; }
    if ($card->quote !== null) { $lines[] = $card->quote['label'].' '.$card->quote['body']; $lines[] = ''; }
    if ($card->button !== null) { $lines[] = $card->button['label'].': '.$card->button['url']; }
    if ($card->link !== null) { $lines[] = $card->link['label'].': '.$card->link['url']; }
    if ($card->button !== null || $card->link !== null) { $lines[] = ''; }
    if ($card->after !== null) { $lines[] = \App\Domains\Shared\Mail\MailCard::plain($card->after); $lines[] = ''; }
    $lines[] = '--';
    $lines[] = $card->footer()['text'];
@endphp
{!! implode("\n", $lines) !!}
