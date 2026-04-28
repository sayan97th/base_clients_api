<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt — {{ $app_name }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';
        $is_credits  = ($currency_type ?? 'usd') === 'credits';

        $category_labels = [
            'link_building'         => 'Link Building',
            'new_content'           => 'New Content',
            'content_optimizations' => 'Content Optimizations',
            'content_briefs'        => 'Content Briefs',
        ];
        $category_colors = [
            'link_building'         => '#ec3c89',
            'new_content'           => '#3b82f6',
            'content_optimizations' => '#8b5cf6',
            'content_briefs'        => '#f59e0b',
        ];
        $category_bgs = [
            'link_building'         => '#fdf2f8',
            'new_content'           => '#eff6ff',
            'content_optimizations' => '#f5f3ff',
            'content_briefs'        => '#fffbeb',
        ];

        $subtotal_amount  = $subtotal_amount ?? 0;
        $discount_amount  = $discount_amount ?? 0;
        $coupon_discounts = $coupon_discounts ?? [];
        $has_discounts    = !empty($coupon_discounts) || $discount_amount > 0;
    @endphp

    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:0;box-sizing:border-box;width:100%;background-color:#f9f0f5;">
        <tr>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
            <td width="600"
                style="box-sizing:border-box;vertical-align:top;display:block;max-width:600px;margin:0 auto;clear:both;">
                <div style="box-sizing:border-box;max-width:600px;margin:0 auto;display:block;padding:24px;">

                    {{-- ── Logo ──────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                        <a href="{{ config('app.frontend_url') }}"
                            style="color:{{ $brand_color }};text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- ── Main card ──────────────────────────────────────── --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:{{ $brand_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:30px 40px 36px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 10px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hey <strong>{{ $user_name }}</strong>,
                                </p>
                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;">
                                    {{ $notification_message }}
                                </p>

                                {{-- Receipt title --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#111827;display:block;margin:24px 0 5px;padding:0;font-size:22px;font-weight:600;">
                                    Receipt
                                </h1>
                                <p align="center"
                                    style="margin:0 0 20px;font-weight:normal;font-size:13px;color:#9ca3af;">
                                    Payment reference {{ $invoice_number }}
                                </p>

                                {{-- ── Line items ─────────────────────────────── --}}
                                <div style="box-sizing:border-box;color:#374151;margin:24px 0 0;">
                                    <table cellpadding="5" cellspacing="0" width="100%"
                                        style="margin:0;box-sizing:border-box;width:100%;">
                                        <tbody>
                                            @foreach ($line_items as $item)
                                                @php
                                                    $item_cat   = $item['category'] ?? null;
                                                    $item_color = $item_cat ? ($category_colors[$item_cat] ?? $brand_color) : null;
                                                    $item_bg    = $item_cat ? ($category_bgs[$item_cat] ?? $brand_bg) : null;
                                                    $item_label = $item_cat ? ($category_labels[$item_cat] ?? $item_cat) : null;
                                                @endphp
                                                <tr>
                                                    <td align="left"
                                                        style="margin:0;box-sizing:border-box;vertical-align:top;text-align:left;border-top:1px dashed #e5e7eb;padding:8px 4px;">
                                                        @if ($item_label)
                                                            <span style="display:inline-block;background-color:{{ $item_bg }};color:{{ $item_color }};font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px;margin-bottom:4px;white-space:nowrap;">
                                                                {{ $item_label }}
                                                            </span><br>
                                                        @endif
                                                        <a href="{{ $invoice_url }}"
                                                            style="color:{{ $brand_color }};text-decoration:none;">
                                                            {{ $item['name'] }}
                                                        </a>
                                                    </td>
                                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px;white-space:nowrap;">
                                                        @if ($is_credits)
                                                            {{ number_format($item['price']) }} credits
                                                        @else
                                                            ${{ number_format($item['price'], 2) }}
                                                        @endif
                                                    </td>
                                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px;white-space:nowrap;color:#6b7280;">
                                                        x{{ $item['quantity'] }}
                                                    </td>
                                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px;white-space:nowrap;font-weight:600;">
                                                        @if ($is_credits)
                                                            {{ number_format($item['item_total']) }} credits
                                                        @else
                                                            ${{ number_format($item['item_total'], 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            {{-- Subtotal (shown only when there are discounts or credits) --}}
                                            @if ($has_discounts || $credit_amount > 0)
                                                <tr>
                                                    <td style="padding:8px 4px 2px;border-top:1px dashed #e5e7eb;" colspan="2">&nbsp;</td>
                                                    <td style="text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px 2px;color:#6b7280;font-size:13px;">
                                                        Subtotal
                                                    </td>
                                                    <td style="text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px 2px;color:#6b7280;font-size:13px;white-space:nowrap;">
                                                        @if ($is_credits)
                                                            {{ number_format($subtotal_amount) }} credits
                                                        @else
                                                            ${{ number_format($subtotal_amount, 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif

                                            {{-- Coupon discounts --}}
                                            @foreach ($coupon_discounts as $coupon)
                                                <tr>
                                                    <td style="padding:6px 4px 2px;border-top:1px dashed #e5e7eb;" colspan="2">
                                                        <span style="font-size:12px;color:#6b7280;">
                                                            {{ $coupon['name'] }}
                                                            @if ($coupon['discount_type'] === 'percentage')
                                                                ({{ $coupon['discount_value'] }}% off)
                                                            @endif
                                                        </span><br>
                                                        <span style="font-size:11px;color:#9ca3af;letter-spacing:0.05em;">{{ $coupon['code'] }}</span>
                                                    </td>
                                                    <td style="text-align:right;vertical-align:middle;border-top:1px dashed #e5e7eb;padding:6px 4px 2px;color:#6b7280;font-size:13px;font-weight:normal;">
                                                        Discount
                                                    </td>
                                                    <td style="text-align:right;vertical-align:middle;border-top:1px dashed #e5e7eb;padding:6px 4px 2px;color:#10b981;font-size:13px;white-space:nowrap;">
                                                        &minus;${{ number_format($coupon['discount_amount'], 2) }}
                                                    </td>
                                                </tr>
                                            @endforeach

                                            {{-- Total paid --}}
                                            <tr>
                                                <td style="padding:10px 4px 4px;border-top:1px dashed #e5e7eb;">&nbsp;</td>
                                                <td style="padding:10px 4px 4px;border-top:1px dashed #e5e7eb;">&nbsp;</td>
                                                <th style="text-align:right;border-top:1px dashed #e5e7eb;padding:10px 4px 4px;font-weight:600;color:#374151;">
                                                    Total Paid
                                                </th>
                                                <th style="text-align:right;border-top:1px dashed #e5e7eb;padding:10px 4px 4px;font-weight:700;color:#111827;">
                                                    @if ($is_credits)
                                                        {{ number_format($total_amount) }} credits
                                                    @else
                                                        ${{ number_format($total_amount, 2) }}
                                                    @endif
                                                </th>
                                            </tr>

                                            {{-- Credit applied --}}
                                            @if ($credit_amount > 0)
                                                <tr>
                                                    <td style="padding:4px;">&nbsp;</td>
                                                    <td style="padding:4px;">&nbsp;</td>
                                                    <td style="text-align:right;padding:4px;color:#6b7280;font-size:13px;font-weight:normal;">
                                                        Credit Applied
                                                    </td>
                                                    <td style="text-align:right;padding:4px;color:#10b981;font-size:13px;white-space:nowrap;">
                                                        @if ($is_credits)
                                                            &minus;{{ number_format($credit_amount) }} credits
                                                        @else
                                                            &minus;${{ number_format($credit_amount, 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif
                                        </tfoot>
                                    </table>
                                </div>

                                {{-- ── Billing details ─────────────────────────── --}}
                                @if ($billed_to)
                                    <h2 style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#111827;display:block;font-size:15px;margin:32px 0 16px;padding:0 0 10px;border-bottom:1px dashed #e5e7eb;font-weight:600;">
                                        Billing Details
                                    </h2>
                                    <table width="100%"
                                        style="margin:0;box-sizing:border-box;width:100%;">
                                        <tr>
                                            <td width="50%"
                                                style="margin:0;box-sizing:border-box;vertical-align:top;text-align:left;">
                                            </td>
                                            <td width="50%"
                                                style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;">
                                                <address style="font-style:normal;font-size:13px;color:#374151;line-height:1.8;">
                                                    @if (!empty($billed_to['company_name']))
                                                        <div>{{ $billed_to['company_name'] }}</div>
                                                    @endif
                                                    @if (!empty($billed_to['address_line_1']))
                                                        <div>{{ $billed_to['address_line_1'] }}</div>
                                                    @endif
                                                    @if (!empty($billed_to['state']))
                                                        <div>{{ $billed_to['state'] }}</div>
                                                    @endif
                                                    @if (!empty($billed_to['country']))
                                                        <div>{{ $billed_to['country'] }}</div>
                                                    @endif
                                                </address>
                                            </td>
                                        </tr>
                                    </table>
                                @endif

                                {{-- ── CTA button ──────────────────────────────── --}}
                                <div style="box-sizing:border-box;text-align:center;margin:48px 0 0;">
                                    <a href="{{ $invoice_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 40px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View in your account
                                    </a>
                                </div>

                                @if (!empty($invoice_pdf_url))
                                    <div style="box-sizing:border-box;font-size:12px;text-align:center;margin:14px 0 0;">
                                        <a href="{{ $invoice_pdf_url }}"
                                            style="color:{{ $brand_color }};text-decoration:none;"
                                            target="_blank">
                                            Download invoice
                                        </a>
                                    </div>
                                @endif

                                {{-- Preferences note --}}
                                <p style="margin:32px 0 0;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this email because your notification preferences are set to
                                    <strong>Email &amp; Portal</strong>.<br>
                                    <a href="{{ $preferences_url }}"
                                        style="color:{{ $brand_color }};text-decoration:none;">
                                        Manage your notification preferences
                                    </a>
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- ── Footer ─────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#9ca3af;padding:20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">
                            &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                        </p>
                        <p style="margin:0;font-size:11px;color:#d1d5db;">
                            Sent to {{ $user_email }}
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
