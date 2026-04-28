<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmed — {{ $app_name }}</title>
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
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:5px;border-top-style:solid;border-top-color:#10b981;border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:30px 40px;">

                                {{-- Success badge --}}
                                <div style="text-align:center;margin:0 0 24px;">
                                    <div
                                        style="display:inline-block;background-color:#10b981;color:#ffffff;width:60px;height:60px;border-radius:50%;line-height:60px;text-align:center;font-size:32px;font-weight:bold;">
                                        &#10003;
                                    </div>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#111827;display:block;margin:0 0 12px;padding:0;font-size:28px;font-weight:600;">
                                    Payment Confirmed!
                                </h1>
                                <p align="center"
                                    style="margin:0 0 24px;font-weight:normal;font-size:15px;color:#6b7280;line-height:1.6;">
                                    Thank you for your payment. We're thrilled to have received your transaction.
                                </p>

                                <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 28px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 20px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $user_name }}</strong>,
                                </p>

                                {{-- Main message --}}
                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    Your payment has been processed successfully! We've issued your receipt and your
                                    account is now up to date. All the details of your transaction are outlined below
                                    for your records.
                                </p>

                                {{-- ── Payment Summary ─────────────────────────── --}}
                                <div
                                    style="box-sizing:border-box;background-color:#f3f4f6;border-radius:6px;padding:20px;margin:28px 0;border-left:4px solid #10b981;">
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                        style="margin:0;box-sizing:border-box;">
                                        <tr>
                                            <td style="padding:6px 0;color:#6b7280;font-size:13px;">
                                                <strong>Invoice Number:</strong>
                                            </td>
                                            <td style="text-align:right;padding:6px 0;color:#111827;font-weight:600;">
                                                {{ $invoice_number }}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:6px 0;color:#6b7280;font-size:13px;">
                                                <strong>Transaction Date:</strong>
                                            </td>
                                            <td style="text-align:right;padding:6px 0;color:#111827;font-weight:600;">
                                                {{ $payment_date }}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:6px 0;color:#6b7280;font-size:13px;">
                                                <strong>Payment Method:</strong>
                                            </td>
                                            <td style="text-align:right;padding:6px 0;color:#111827;font-weight:600;">
                                                {{ $payment_method }}
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                {{-- ── Order Summary ───────────────────────────── --}}
                                <div style="box-sizing:border-box;color:#374151;margin:24px 0 0;">
                                    <p style="margin:0 0 12px;font-size:12px;font-weight:700;color:{{ $brand_color }};text-transform:uppercase;letter-spacing:0.5px;">
                                        Order Summary
                                    </p>
                                    <table cellpadding="0" cellspacing="0" width="100%"
                                        style="margin:0;box-sizing:border-box;width:100%;border-collapse:collapse;">
                                        <tbody>
                                            @foreach ($line_items as $item)
                                                @php
                                                    $item_cat   = $item['category'] ?? null;
                                                    $item_color = $item_cat ? ($category_colors[$item_cat] ?? $brand_color) : null;
                                                    $item_bg    = $item_cat ? ($category_bgs[$item_cat] ?? $brand_bg) : null;
                                                    $item_label = $item_cat ? ($category_labels[$item_cat] ?? $item_cat) : null;
                                                @endphp
                                                <tr>
                                                    <td style="padding:12px 0 4px;border-top:1px solid #e5e7eb;vertical-align:top;">
                                                        @if ($item_label)
                                                            <span style="display:inline-block;background-color:{{ $item_bg }};color:{{ $item_color }};font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px;margin-bottom:4px;white-space:nowrap;">
                                                                {{ $item_label }}
                                                            </span><br>
                                                        @endif
                                                        <strong style="color:#374151;font-size:14px;">{{ $item['name'] }}</strong>
                                                        @if (!empty($item['description']))
                                                            <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
                                                                {{ $item['description'] }}
                                                            </p>
                                                        @endif
                                                    </td>
                                                    <td style="padding:12px 0 4px;border-top:1px solid #e5e7eb;text-align:right;vertical-align:top;white-space:nowrap;font-weight:700;color:#111827;">
                                                        @if ($is_credits)
                                                            {{ number_format($item['item_total']) }} credits
                                                        @else
                                                            ${{ number_format($item['item_total'], 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" style="padding:0 0 10px;border-bottom:1px solid #e5e7eb;font-size:12px;color:#6b7280;">
                                                        Qty: <strong>{{ $item['quantity'] }}</strong>
                                                        &nbsp;&middot;&nbsp;
                                                        Unit price:
                                                        <strong>
                                                            @if ($is_credits)
                                                                {{ number_format($item['price']) }} credits
                                                            @else
                                                                ${{ number_format($item['price'], 2) }}
                                                            @endif
                                                        </strong>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            {{-- Coupon discounts --}}
                                            @if (!empty($coupon_discounts))
                                                @foreach ($coupon_discounts as $coupon)
                                                    <tr>
                                                        <td style="padding:8px 0;border-bottom:1px dashed #e5e7eb;font-size:12px;color:#6b7280;">
                                                            {{ $coupon['name'] }}
                                                            @if ($coupon['discount_type'] === 'percentage')
                                                                ({{ $coupon['discount_value'] }}% off)
                                                            @else
                                                                (Fixed discount)
                                                            @endif
                                                            &nbsp;&mdash;&nbsp;
                                                            <span style="font-size:11px;letter-spacing:0.05em;">{{ $coupon['code'] }}</span>
                                                        </td>
                                                        <td style="padding:8px 0;border-bottom:1px dashed #e5e7eb;text-align:right;font-weight:600;color:#10b981;white-space:nowrap;">
                                                            &minus;{{ $coupon['discount_amount'] }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endif

                                            {{-- Subtotal --}}
                                            <tr>
                                                <td style="padding:10px 0;text-align:left;color:#6b7280;font-size:13px;">
                                                    Subtotal
                                                </td>
                                                <td style="padding:10px 0;text-align:right;color:#6b7280;font-size:13px;white-space:nowrap;">
                                                    @if ($is_credits)
                                                        {{ number_format($subtotal_amount) }} credits
                                                    @else
                                                        ${{ number_format($subtotal_amount, 2) }}
                                                    @endif
                                                </td>
                                            </tr>

                                            {{-- Discount --}}
                                            @if ($discount_amount > 0)
                                                <tr>
                                                    <td style="padding:6px 0;text-align:left;color:#6b7280;font-size:13px;">
                                                        Discount
                                                    </td>
                                                    <td style="padding:6px 0;text-align:right;color:#10b981;font-weight:600;white-space:nowrap;">
                                                        &minus;
                                                        @if ($is_credits)
                                                            {{ number_format($discount_amount) }} credits
                                                        @else
                                                            ${{ number_format($discount_amount, 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif

                                            {{-- Credit applied --}}
                                            @if ($credit_amount > 0)
                                                <tr>
                                                    <td style="padding:6px 0;text-align:left;color:#6b7280;font-size:13px;">
                                                        Credit Applied
                                                    </td>
                                                    <td style="padding:6px 0;text-align:right;color:#10b981;font-weight:600;white-space:nowrap;">
                                                        &minus;
                                                        @if ($is_credits)
                                                            {{ number_format($credit_amount) }} credits
                                                        @else
                                                            ${{ number_format($credit_amount, 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif

                                            {{-- Total paid --}}
                                            <tr>
                                                <td style="padding:12px 0;border-top:2px solid #111827;text-align:left;color:#111827;font-weight:700;font-size:15px;">
                                                    Total Paid
                                                </td>
                                                <td style="padding:12px 0;border-top:2px solid #111827;text-align:right;color:#111827;font-weight:700;font-size:15px;white-space:nowrap;">
                                                    @if ($is_credits)
                                                        {{ number_format($total_amount) }} credits
                                                    @else
                                                        ${{ number_format($total_amount, 2) }}
                                                    @endif
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                {{-- ── Billing details ──────────────────────────── --}}
                                @if (!empty($billed_to))
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                        style="margin:28px 0 0;box-sizing:border-box;background-color:#f9f0f5;border-radius:6px;padding:16px;">
                                        <tr>
                                            <td style="margin:0;box-sizing:border-box;vertical-align:top;">
                                                <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:{{ $brand_color }};text-transform:uppercase;letter-spacing:0.5px;">
                                                    Billed To
                                                </p>
                                                <address style="font-style:normal;font-size:13px;color:#374151;line-height:1.8;">
                                                    @if (!empty($billed_to['company_name']))
                                                        <div><strong>{{ $billed_to['company_name'] }}</strong></div>
                                                    @endif
                                                    @if (!empty($billed_to['company_description']))
                                                        <div style="font-size:12px;color:#6b7280;">
                                                            {{ $billed_to['company_description'] }}
                                                        </div>
                                                    @endif
                                                    @if (!empty($billed_to['address_line_1']))
                                                        <div>{{ $billed_to['address_line_1'] }}</div>
                                                    @endif
                                                    @if (!empty($billed_to['address_line_2']))
                                                        <div>{{ $billed_to['address_line_2'] }}</div>
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
                                <div style="box-sizing:border-box;text-align:center;margin:36px 0 0;">
                                    <a href="{{ $invoice_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:#10b981;padding:14px 52px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Full Receipt
                                    </a>
                                </div>

                                {{-- Additional note --}}
                                <p style="margin:28px 0 0;font-weight:normal;font-size:13px;color:#6b7280;text-align:center;line-height:1.6;">
                                    A copy of this receipt has been saved to your account for future reference.<br>
                                    For any questions about your payment, please don't hesitate to
                                    <a href="{{ config('app.frontend_url') }}/contact"
                                        style="color:{{ $brand_color }};text-decoration:none;">contact our support team</a>.
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- ── Footer ──────────────────────────────────────────── --}}
                    <div style="margin:24px 0 0;text-align:center;">
                        <p style="margin:0;font-weight:normal;font-size:11px;color:#9ca3af;text-align:center;line-height:1.6;">
                            &copy; {{ now()->year }} {{ $app_name }}. All rights reserved.<br>
                            <a href="{{ config('app.frontend_url') }}/privacy"
                                style="color:#9ca3af;text-decoration:none;font-size:11px;">Privacy Policy</a> |
                            <a href="{{ config('app.frontend_url') }}/terms"
                                style="color:#9ca3af;text-decoration:none;font-size:11px;">Terms of Service</a>
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>

</body>

</html>
