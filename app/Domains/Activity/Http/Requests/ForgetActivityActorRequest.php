<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Forgets one actor's events: from the app with its server token, or from the event page.
 */
class ForgetActivityActorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        // The web route: someone who may change the project.
        if ($project instanceof Project) {
            return (bool) $this->user()?->can('update', $project);
        }

        // The API route: the project of the server token.
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:128'],
            'id' => ['required', 'string', 'max:64'],
        ];
    }

    public function project(): Project
    {
        $project = $this->route('project');

        /** @var Project */
        return $project instanceof Project ? $project : $this->attributes->get('project');
    }
}
