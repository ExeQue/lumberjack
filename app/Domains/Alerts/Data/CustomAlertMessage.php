<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\Concerns\LabelsMaintenance;
use App\Domains\Alerts\Enums\CustomAlertLevel;
use App\Domains\Alerts\Models\CustomAlert;
use Override;

/**
 * A custom alert as the channels send it.
 */
final readonly class CustomAlertMessage implements Alert
{
    use LabelsMaintenance;

    public function __construct(
        public CustomAlert $alert,
    ) {
    }

    public function level(): CustomAlertLevel
    {
        return $this->alert->level;
    }

    /** Where the title links to, when the app gave a place. */
    public function titleUrl(): ?string
    {
        return $this->alert->content['url'];
    }

    /** @return list<array{name: string, value: string, inline: bool}> */
    public function fields(): array
    {
        return $this->alert->content['fields'];
    }

    /** @return list<array{title: ?string, headers: list<string>, rows: list<list<string>>, omitted: int}> */
    public function tables(): array
    {
        return $this->alert->content['tables'];
    }

    /** @return list<array{label: string, url: string, style: ?string}> */
    public function links(): array
    {
        return $this->alert->content['links'];
    }

    /** The project and environment, on every message: one channel can serve many projects. */
    public function context(): string
    {
        return implode(' · ', array_filter([$this->alert->project->name, $this->alert->environment]));
    }

    /**
     * A table as aligned text, for channels that have only a monospace block.
     *
     * @param  array{title: ?string, headers: list<string>, rows: list<list<string>>, omitted: int}  $table
     */
    public static function tableText(array $table): string
    {
        $rows = [$table['headers'], ...$table['rows']];
        $widths = array_map(fn (int $column): int => max(array_map(fn (array $row): int => mb_strlen($row[$column] ?? ''), $rows)), array_keys($table['headers']));
        $line = fn (array $row): string => rtrim(implode('  ', array_map(fn (int $column, int $width): string => ($row[$column] ?? '').str_repeat(' ', $width - mb_strlen($row[$column] ?? '')), array_keys($widths), $widths)));
        $lines = [$line($table['headers']), implode('  ', array_map(fn (int $width): string => str_repeat('-', $width), $widths)), ...array_map($line, $table['rows'])];

        if ($table['omitted'] > 0) {
            $lines[] = trans_choice('alerts.custom.rows_omitted', $table['omitted']);
        }

        return implode("\n", $lines);
    }

    /**
     * The message, fields and tables as plain lines, for channels that take only text.
     *
     * @return list<string>
     */
    public function plainLines(): array
    {
        $lines = $this->alert->message !== null ? [$this->alert->message] : [];

        foreach ($this->fields() as $field) {
            $lines[] = $field['name'].': '.$field['value'];
        }

        foreach ($this->tables() as $table) {
            if ($table['title'] !== null) {
                $lines[] = $table['title'];
            }

            foreach ($table['rows'] as $row) {
                $lines[] = implode(', ', array_map(fn (string $header, ?string $cell): string => $header.': '.$cell, $table['headers'], array_pad($row, count($table['headers']), '')));
            }

            if ($table['omitted'] > 0) {
                $lines[] = trans_choice('alerts.custom.rows_omitted', $table['omitted']);
            }
        }

        $lines[] = $this->context();

        return $lines;
    }

    #[Override]
    public function headline(): string
    {
        return $this->marked($this->alert->title);
    }

    #[Override]
    public function summary(): array
    {
        return [
            'title' => $this->headline(),
            'subject' => $this->context(),
            'body' => $this->alert->message !== null ? [$this->alert->message] : [],
        ];
    }

    #[Override]
    public function facts(): array
    {
        return array_column($this->fields(), 'value', 'name');
    }

    #[Override]
    public function urgency(): string
    {
        return $this->alert->level->urgency();
    }

    #[Override]
    public function url(): string
    {
        return $this->titleUrl() ?? $this->links()[0]['url'] ?? route('alerts.index');
    }

    #[Override]
    protected function maintenanceScope(): array
    {
        return [$this->alert->project_id, $this->alert->environment];
    }
}
