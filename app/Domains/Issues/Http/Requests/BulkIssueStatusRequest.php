<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Http\Requests\Concerns\TargetsSelectedIssues;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkIssueStatusRequest extends FormRequest
{
    use TargetsSelectedIssues;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(IssueStatus::class)],
            ...$this->issueRules(),
        ];
    }
}
