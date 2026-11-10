<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Http\Requests;

use App\Domains\Connectors\Mosparo\Data\MosparoCredentials;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Rules\PublicUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A mosparo instance's https address, a project's key pair, and the name it is shown by.
 */
class ConnectMosparoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string', 'url:https', 'max:2000', new PublicUrl((bool) config('health.data_sources_allow_private', false))],
            'public_key' => ['required', 'string', 'max:200'],
            'private_key' => ['required', 'string', 'max:1000'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc'],
        ];
    }

    public function credentials(): MosparoCredentials
    {
        return new MosparoCredentials(rtrim($this->string('url')->toString(), '/'), $this->string('public_key')->trim()->toString(), $this->string('private_key')->trim()->toString());
    }
}
