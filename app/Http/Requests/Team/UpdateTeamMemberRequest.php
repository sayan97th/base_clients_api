<?php

namespace App\Http\Requests\Team;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $this->user()->hasTeamPermission($team, 'team_members.manage');
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', 'in:manager,member'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', Team::TEAM_PERMISSIONS)],
        ];
    }
}
