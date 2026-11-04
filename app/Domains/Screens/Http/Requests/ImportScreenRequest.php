<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenFile;
use App\Domains\Teams\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * A screen file to import into the current team, uploaded or pasted, at most
 * ScreenFile::MAX_KILOBYTES. Its contents are checked by ImportScreenAction.
 */
class ImportScreenRequest extends FormRequest
{
    /** @var array<mixed>|null */
    private ?array $document = null;

    public function team(): Team
    {
        /** @var Team */
        return $this->user()->currentTeam;
    }

    public function authorize(): bool
    {
        return $this->user()?->currentTeam !== null && $this->user()->can('create', [Screen::class, $this->team()]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required_without:json', 'nullable', 'file', 'max:'.ScreenFile::MAX_KILOBYTES],
            'json' => ['required_without:file', 'nullable', 'string', 'max:'.(ScreenFile::MAX_KILOBYTES * 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => __('ui/screens.import.errors.size', ['max' => ScreenFile::MAX_KILOBYTES]),
            'json.max' => __('ui/screens.import.errors.size', ['max' => ScreenFile::MAX_KILOBYTES]),
            'file.required_without' => __('ui/screens.import.errors.missing'),
            'json.required_without' => __('ui/screens.import.errors.missing'),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $file = $this->file('file');
            $text = $file instanceof UploadedFile ? (string) $file->get() : $this->string('json')->toString();
            $document = json_decode($text, true, 64);

            if (! is_array($document) || array_is_list($document)) {
                $validator->errors()->add($file instanceof UploadedFile ? 'file' : 'json', __('ui/screens.import.errors.json'));

                return;
            }

            $this->document = $document;
        }];
    }

    /** @return array<mixed> the decoded file, after validation */
    public function document(): array
    {
        return $this->document ?? [];
    }
}
