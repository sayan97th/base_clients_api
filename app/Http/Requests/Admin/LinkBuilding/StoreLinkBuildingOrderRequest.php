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
        $normalized = static::normalizeRowInput($this->all());

        if (! empty($normalized)) {
            $this->merge($normalized);
        }
    }

    /**
     * Normalized values for one order row: URL fields get an https:// prefix (or
     * become null when blank) and exact_match "Yes"/"No" becomes a boolean. Only the
     * keys that need a new value are returned. Shared with
     * BatchStoreLinkBuildingOrdersRequest, which applies it to every pasted row.
     */
    public static function normalizeRowInput(array $row): array
    {
        $url_fields = ['landing_page', 'live_link', 'partnership'];

        $normalized = [];
        foreach ($url_fields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];

            if ($value === '' || $value === null) {
                $normalized[$field] = null;
            } elseif (is_string($value) && ! preg_match('#^https?://#i', $value)) {
                $normalized[$field] = 'https://' . $value;
            }
        }

        if (array_key_exists('exact_match', $row)) {
            $normalized['exact_match'] = $row['exact_match'] === 'Yes';
        }

        return $normalized;
    }

    public function rules(): array
    {
        return static::rowRules();
    }

    /** Validation rules for one order row, shared with BatchStoreLinkBuildingOrdersRequest. */
    public static function rowRules(): array
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
            'niche'                     => 'nullable|string|max:255',
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
