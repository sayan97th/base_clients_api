<?php

namespace App\Http\Requests\Admin\BacklinkOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBacklinkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Convert empty strings to null for URL fields so that nullable|url
     * validation does not reject an empty string (which is not a valid URL).
     */
    protected function prepareForValidation(): void
    {
        $url_fields = ['landing_page', 'article', 'live_link', 'partnership'];

        $normalized = [];
        foreach ($url_fields as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $normalized[$field] = null;
            }
        }

        if (! empty($normalized)) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'order_id'                  => 'required|string|max:50|unique:backlink_orders,order_id,' . $id,
            'team_specific_link_id'     => 'nullable|string|max:50',
            'link_type'                 => 'nullable|string|in:DA 30+ External,DA 40+ External,DA 50+ External,DA 30+ Internal,DA 40+ Internal',
            'client'                    => 'nullable|string|max:255',
            'keyword'                   => 'nullable|string|max:500',
            'landing_page'              => 'nullable|url|max:2000',
            'exact_match'               => 'nullable|in:Yes,No',
            'notes'                     => 'nullable|string',
            'request_date'              => 'nullable|string|max:20',
            'estimated_delivery_date'   => 'nullable|string|max:20',
            'estimated_turnaround_days' => 'nullable|integer|min:0',
            'link_builder_user_id'      => 'nullable|integer|exists:users,id',
            'link_builder'              => 'nullable|string|max:255',
            'pen_name'                  => 'nullable|string|max:255',
            'partnership'               => 'nullable|url|max:2000',
            'article_title'             => 'nullable|string|max:500',
            'article'                   => 'nullable|url|max:2000',
            'status'                    => 'nullable|string|in:New Request,Reviewing,Ordered,Pending,Live,Quality Control,Cancelled',
            'live_link'                 => 'nullable|url|max:2000',
            'live_link_date'            => 'nullable|string|max:20',
            'dr_lbs'                    => 'nullable|string|max:20',
            'posting_fee_lbs'           => 'nullable|string|max:50',
            'current_traffic'           => 'nullable|string|max:50',
            'dr_formula'                => 'nullable|string|max:50',
            'current_poc'               => 'nullable|string|max:255',
            'current_price'             => 'nullable|string|max:100',
            'lb_tl_approval'            => 'nullable|string|max:255',
            'approval_date'             => 'nullable|string|max:20',
            'final_price'               => 'nullable|string|max:100',
        ];
    }
}
