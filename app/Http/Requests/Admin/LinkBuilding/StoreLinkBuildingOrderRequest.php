<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use Illuminate\Foundation\Http\FormRequest;

class StoreLinkBuildingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize URL fields and convert exact_match "Yes"/"No" to boolean.
     */
    protected function prepareForValidation(): void
    {
        $url_fields = ['landing_page', 'live_link', 'partnership'];

        $normalized = [];
        foreach ($url_fields as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value === '' || $value === null) {
                $normalized[$field] = null;
            } elseif (! preg_match('#^https?://#i', $value)) {
                $normalized[$field] = 'https://' . $value;
            }
        }

        if ($this->has('exact_match')) {
            $normalized['exact_match'] = $this->input('exact_match') === 'Yes';
        }

        if (! empty($normalized)) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'order_id'                  => 'nullable|string|max:50',
            'team_specific_link_id'     => 'nullable|string|max:50',
            'link_type'                 => 'required|string|max:255',
            'client'                    => 'required|string|max:255',
            'keyword'                   => 'required|string|max:500',
            'landing_page'              => 'required|url|max:2000',
            'exact_match'               => 'nullable|boolean',
            'notes'                     => 'nullable|string',
            'internal_notes'            => 'nullable|string',
            'request_date'              => 'nullable|string|max:20',
            'estimated_delivery_date'   => 'nullable|string|max:20',
            'estimated_turnaround_days' => 'nullable|string|max:20',
            'link_builder_user_id'      => 'nullable|integer|exists:users,id',
            'link_builder'              => 'nullable|string|max:255',
            'pen_name'                  => 'nullable|string|max:255',
            'partnership'               => 'nullable|url|max:2000',
            'partnership_check'         => 'nullable|string|in:Approved,Not Approved,Ready,Rejected,Scheduled',
            'article_title'             => 'nullable|string|max:500',
            'article'                   => 'nullable|string|max:2000',
            // Not restricted to a fixed enum: the dashboard's status dropdown offers a
            // preset list, but admins also paste status values copied straight from the
            // external BASE link sheet, which does not always match that preset list.
            'status'                    => 'nullable|string|max:255',
            'live_link'                 => 'nullable|url|max:2000',
            'live_link_date'            => 'nullable|string|max:20',
            'dr_lbs'                    => 'nullable|string|max:50',
            'posting_fee_lbs'           => 'nullable|string|max:50',
            'current_traffic'           => 'nullable|string|max:50',
            'dr_formula'                => 'nullable|string|max:50',
            'current_poc'               => 'nullable|string|max:255',
            'current_price'             => 'nullable|string|max:100',
            'lb_tl_approval'            => 'nullable|string|max:255',
            'approval_date'             => 'nullable|string|max:20',
            'final_price'               => 'nullable|string|max:100',
            'currency'                  => 'nullable|string|in:USD,EUR',
            'user_id'                   => 'nullable|integer|exists:users,id',
            'admin_team_id'             => 'nullable|uuid|exists:admin_teams,id',
            'assigned_admin_user_id'    => 'nullable|integer|exists:users,id',
        ];
    }
}
