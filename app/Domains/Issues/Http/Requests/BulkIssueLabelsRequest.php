<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Http\Requests\Concerns\TargetsSelectedIssues;
use App\Domains\Issues\Models\Label;
use Illuminate\Foundation\Http\FormRequest;

class BulkIssueLabelsRequest extends FormRequest
{
    use TargetsSelectedIssues;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:'.Label::MAX_NAME],
            'remove' => ['boolean'],
            ...$this->issueRules(),
        ];
    }
}
