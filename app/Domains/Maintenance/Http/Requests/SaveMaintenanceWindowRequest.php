<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A planned window: the environment (empty for every one), start, planned end and message.
 */
class SaveMaintenanceWindowRequest extends FormRequest
{
    /** Before validation, so one without the right is not told what is wrong with the form. */
    public function authorize(): bool
    {
        $subject = $this->route('maintenanceWindow') ?? $this->route('project');

        return ($subject instanceof MaintenanceWindow || $subject instanceof Project) && $this->user()?->can('update', $subject) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * Also used by MCP's create and update tools.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(): array
    {
        return [
            'environment' => ['nullable', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('starts_at')->toString())->setTimezone((string) config('app.timezone'));
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_at')->toString())->setTimezone((string) config('app.timezone'));
    }
}
