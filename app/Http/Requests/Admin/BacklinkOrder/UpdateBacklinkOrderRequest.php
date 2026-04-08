<?php

namespace App\Http\Requests\Admin\BacklinkOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBacklinkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'order_id'                  => 'required|string|max:50|unique:backlink_orders,order_id,' . $id,
            'team_specific_link_id'     => 'nullable|string|max:50',
            'link_type'                 => 'required|string|in:DA 30+ External,DA 40+ External,DA 50+ External,DA 30+ Internal,DA 40+ Internal',
            'client'                    => 'required|string|max:255',
            'keyword'                   => 'required|string|max:500',
            'landing_page'              => 'required|url|max:2000',
            'exact_match'               => 'required|in:Yes,No',
            'notes'                     => 'nullable|string',
            'request_date'              => 'nullable|string|max:20',
            'estimated_delivery_date'   => 'nullable|string|max:20',
            'estimated_turnaround_days' => 'nullable|integer|min:0',
            'link_builder_user_id'      => 'nullable|integer|exists:users,id',
            'link_builder'              => 'nullable|string|max:255',
            'pen_name'                  => 'nullable|string|max:255',
            'partnership'               => 'nullable|string|max:2000',
            'article_title'             => 'nullable|string|max:500',
            'article'                   => 'nullable|url|max:2000',
            'status'                    => 'required|in:New Request,Reviewing,Ordered,Pending,Live,Quality Control,Cancelled',
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
