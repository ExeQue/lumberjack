<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use Illuminate\Foundation\Http\FormRequest;

class SetIssueLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()?->can('update', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'labels' => ['present', 'array', 'max:'.Label::MAX_PER_ISSUE],
            'labels.*' => ['string', 'max:'.Label::MAX_NAME],
        ];
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_values(array_map('strval', (array) $this->input('labels', [])));
    }
}
