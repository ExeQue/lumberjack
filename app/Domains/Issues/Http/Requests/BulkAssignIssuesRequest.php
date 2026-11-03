<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Http\Requests\Concerns\TargetsSelectedIssues;
use Illuminate\Foundation\Http\FormRequest;

class BulkAssignIssuesRequest extends FormRequest
{
    use TargetsSelectedIssues;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            ...$this->issueRules(),
        ];
    }
}
