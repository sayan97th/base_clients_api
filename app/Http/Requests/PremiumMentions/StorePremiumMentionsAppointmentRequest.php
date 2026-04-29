<?php

namespace App\Http\Requests\PremiumMentions;

use App\Models\PremiumMentionsPlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePremiumMentionsAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_uri'   => ['required', 'string', 'url'],
            'invitee_uri' => ['required', 'string', 'url'],
            'plan_id'     => ['required', 'string', 'exists:premium_mentions_plans,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $plan_id = $this->input('plan_id');

            if ($plan_id && !$validator->errors()->has('plan_id')) {
                $plan = PremiumMentionsPlan::find($plan_id);

                if (!$plan || !$plan->is_active) {
                    $validator->errors()->add('plan_id', 'The selected plan id is invalid.');
                }
            }
        });
    }
}
