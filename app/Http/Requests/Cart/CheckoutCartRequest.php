<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method_id'   => ['required', 'string'],
            'total_amount'        => ['required', 'numeric', 'min:0.01'],
            'credits_amount'      => ['nullable', 'numeric', 'min:0'],
            'session_id'          => ['nullable', 'string', 'uuid'],
            'coupon_ids'          => ['nullable', 'array'],
            'coupon_ids.*'        => ['string', 'uuid'],

            'billing'                   => ['required', 'array'],
            'billing.company'           => ['nullable', 'string', 'max:255'],
            'billing.address'           => ['nullable', 'string', 'max:500'],
            'billing.city'              => ['nullable', 'string', 'max:255'],
            'billing.state'             => ['nullable', 'string', 'max:255'],
            'billing.country'           => ['nullable', 'string', 'max:255'],
            'billing.postal_code'       => ['nullable', 'string', 'max:20'],

            'order_title'         => ['nullable', 'string', 'max:500'],
            'order_notes'         => ['nullable', 'string', 'max:5000'],

            'link_building_items'                        => ['nullable', 'array'],
            'link_building_items.*.dr_tier_id'           => ['required_with:link_building_items', 'string', 'exists:dr_tiers,id'],
            'link_building_items.*.quantity'             => ['required_with:link_building_items', 'integer', 'min:1'],
            'link_building_items.*.unit_price'           => ['required_with:link_building_items', 'numeric', 'min:0'],
            'link_building_items.*.placements'           => ['required_with:link_building_items', 'array'],
            'link_building_items.*.placements.*.row_index'    => ['required', 'integer', 'min:0'],
            'link_building_items.*.placements.*.keyword'      => ['nullable', 'string', 'max:500'],
            'link_building_items.*.placements.*.landing_page' => ['nullable', 'string', 'max:2048'],
            'link_building_items.*.placements.*.exact_match'  => ['required', 'boolean'],

            'content_optimization_items'                                         => ['nullable', 'array'],
            'content_optimization_items.*.tier_id'                               => ['required_with:content_optimization_items', 'string', 'exists:content_optimization_tiers,id'],
            'content_optimization_items.*.quantity'                              => ['required_with:content_optimization_items', 'integer', 'min:1'],
            'content_optimization_items.*.unit_price'                            => ['required_with:content_optimization_items', 'numeric', 'min:0'],
            'content_optimization_items.*.intake_rows'                           => ['nullable', 'array'],
            'content_optimization_items.*.intake_rows.*.primary_keyword'         => ['nullable', 'string', 'max:500'],
            'content_optimization_items.*.intake_rows.*.secondary_keywords'      => ['nullable', 'string', 'max:500'],
            'content_optimization_items.*.intake_rows.*.content_page_url'        => ['nullable', 'string', 'max:2000'],
            'content_optimization_items.*.intake_rows.*.notes'                   => ['nullable', 'string', 'max:5000'],

            'new_content_items'                                      => ['nullable', 'array'],
            'new_content_items.*.tier_id'                            => ['required_with:new_content_items', 'string', 'exists:new_content_tiers,id'],
            'new_content_items.*.quantity'                           => ['required_with:new_content_items', 'integer', 'min:1'],
            'new_content_items.*.unit_price'                         => ['required_with:new_content_items', 'numeric', 'min:0'],
            'new_content_items.*.intake_rows'                        => ['nullable', 'array'],
            'new_content_items.*.intake_rows.*.keyword_phrase'       => ['nullable', 'string', 'max:500'],
            'new_content_items.*.intake_rows.*.secondary_keywords'   => ['nullable', 'string', 'max:500'],
            'new_content_items.*.intake_rows.*.type_of_content'      => ['nullable', 'string', 'in:Blog Article,Product Page,Home Page,About Us Page,Other'],
            'new_content_items.*.intake_rows.*.notes'                => ['nullable', 'string', 'max:5000'],

            'content_brief_items'                                         => ['nullable', 'array'],
            'content_brief_items.*.tier_id'                               => ['required_with:content_brief_items', 'string', 'exists:content_brief_tiers,id'],
            'content_brief_items.*.quantity'                              => ['required_with:content_brief_items', 'integer', 'min:1'],
            'content_brief_items.*.unit_price'                            => ['required_with:content_brief_items', 'numeric', 'min:0'],
            'content_brief_items.*.intake_rows'                           => ['nullable', 'array'],
            'content_brief_items.*.intake_rows.*.primary_keyword'         => ['nullable', 'string', 'max:255'],
            'content_brief_items.*.intake_rows.*.secondary_keywords'      => ['nullable', 'string', 'max:500'],
            'content_brief_items.*.intake_rows.*.content_page_url'        => ['nullable', 'string', 'max:2048'],
            'content_brief_items.*.intake_rows.*.notes'                   => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $has_items = !empty($this->input('link_building_items'))
                || !empty($this->input('content_optimization_items'))
                || !empty($this->input('new_content_items'))
                || !empty($this->input('content_brief_items'));

            if (!$has_items) {
                $validator->errors()->add('items', 'At least one item array must contain items.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'total_amount.min' => 'The total amount must be a positive number.',
        ];
    }
}
