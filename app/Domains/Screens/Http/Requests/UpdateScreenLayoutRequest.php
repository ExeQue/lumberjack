<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScreenLayoutRequest extends FormRequest
{
    public function screen(): Screen
    {
        /** @var Screen */
        return $this->route('screen');
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->screen()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cols = max(Screen::MIN_COLS, min(Screen::MAX_COLS, $this->integer('cols')));
        $rows = max(Screen::MIN_ROWS, min(Screen::MAX_ROWS, $this->integer('rows')));
        $projects = array_values($this->screen()->team->projects()->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all());
        $rules = [
            'cols' => ['required', 'integer', 'min:'.Screen::MIN_COLS, 'max:'.Screen::MAX_COLS],
            'rows' => ['required', 'integer', 'min:'.Screen::MIN_ROWS, 'max:'.Screen::MAX_ROWS],
            'widgets' => ['present', 'array', 'max:'.WidgetSchema::MAX_WIDGETS],
        ];

        foreach (array_slice((array) $this->input('widgets', []), 0, WidgetSchema::MAX_WIDGETS, true) as $i => $widget) {
            $rules = [...$rules, ...WidgetSchema::rules("widgets.{$i}", $widget, $cols, $rows, $projects)];
        }

        return $rules;
    }

    /**
     * Each widget id once: the designer and kiosks tell widgets apart by it.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('widgets', []) as $i => $widget) {
                $id = is_array($widget) ? ($widget['id'] ?? null) : null;

                if (is_string($id) && isset($seen[$id])) {
                    $validator->errors()->add("widgets.{$i}.id", __('ui/screens.validation.duplicate_id'));
                }

                $seen[(string) $id] = true;
            }
        }];
    }
}
