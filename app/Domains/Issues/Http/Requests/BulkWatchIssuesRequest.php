<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests;

use App\Domains\Issues\Http\Requests\Concerns\TargetsSelectedIssues;
use Illuminate\Foundation\Http\FormRequest;

class BulkWatchIssuesRequest extends FormRequest
{
    use TargetsSelectedIssues;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->issueRules();
    }
}
