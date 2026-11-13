<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Http\Requests;

use App\Domains\Connectors\Packagist\Support\PackagistClient;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A package's name on Packagist, vendor/name.
 */
class ConnectPackagistRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['package' => mb_strtolower(trim($this->string('package')->toString()))]);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'package' => ['required', 'string', 'max:200', 'regex:'.PackagistClient::NAME_PATTERN],
        ];
    }
}
