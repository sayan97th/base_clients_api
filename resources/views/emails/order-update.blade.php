<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Update — {{ $app_name }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';

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

        $purchase_items = $purchase_items ?? [];
        $purchase_title = $purchase_title ?? null;
        $is_multi       = !empty($purchase_items);

        $order_reference = $order_reference ?? null;
        $order_title     = $order_title ?? null;
        $purchase_date   = $purchase_date ?? null;
        $link_count      = $link_count ?? null;
        $dr_tier_summary = $dr_tier_summary ?? null;

        // Link count + DR tier collapse into one "Details" value (e.g. "10 links · DR 50-59")
        // so the reference strip stays a single compact row instead of a grid of boxes.
        // Purchase date is intentionally left out here — it's already stated in the
        // heading copy above whenever it's relevant, so repeating it would be redundant.
        $order_details = collect([
            $link_count ? ($link_count . ' link' . ($link_count === 1 ? '' : 's')) : null,
            $dr_tier_summary,
        ])->filter()->implode(' · ');

        $meta_fields = [];
        if ($order_reference) {
            $meta_fields[] = ['label' => 'Order ID', 'value' => $order_reference];
        }
        if ($order_title) {
            $meta_fields[] = ['label' => 'Order', 'value' => $order_title];
        }
        if ($order_details) {
            $meta_fields[] = ['label' => 'Details', 'value' => $order_details];
        }
        $has_order_meta = !empty($meta_fields);
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
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0 40px 36px;">

                                {{-- Type badge --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        ORDER UPDATE
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    {{ $update_title }}
                                </h1>

                                {{-- Sub-heading --}}
                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    @if ($purchase_title)
                                        {{ $purchase_title }}
                                    @elseif ($order_title && $purchase_date)
                                        An update to your order {{ $order_title }}, purchased on {{ $purchase_date }}
                                    @elseif ($order_title)
                                        An update to your order: {{ $order_title }}
                                    @elseif ($purchase_date)
                                        An update to the order you purchased on {{ $purchase_date }}
                                    @else
                                        There has been an update to one of your orders.
                                    @endif
                                </p>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $user_name }}</strong>,
                                </p>

                                {{-- Update message --}}
                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    {{ $update_message }}
                                </p>

                                {{-- Multi-purchase item breakdown --}}
                                @if ($is_multi)
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                        style="margin:0 0 28px;border-collapse:collapse;">
                                        @foreach ($purchase_items as $p_item)
                                            @php
                                                $p_cat   = $p_item['category'] ?? 'link_building';
                                                $p_color = $category_colors[$p_cat] ?? $brand_color;
                                                $p_bg    = $category_bgs[$p_cat] ?? $brand_bg;
                                                $p_label = $category_labels[$p_cat] ?? $p_cat;
                                            @endphp
                                            <tr>
                                                <td style="padding:10px 0;border-bottom:1px solid #f3e8ef;vertical-align:top;">
                                                    <span style="display:inline-block;background-color:{{ $p_bg }};color:{{ $p_color }};font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;white-space:nowrap;margin-right:8px;">
                                                        {{ $p_label }}
                                                    </span>
                                                    <span style="font-size:13px;color:#374151;font-weight:600;">
                                                        {{ $p_item['title'] ?? '' }}
                                                    </span>
                                                    @if (!empty($p_item['update']))
                                                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280;line-height:1.5;">
                                                            {{ $p_item['update'] }}
                                                        </p>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </table>
                                @endif

                                {{-- Info box --}}
                                <div style="box-sizing:border-box;background-color:#fdf2f8;border-radius:6px;padding:16px 20px;margin:0 0 28px;">
                                    <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:{{ $brand_color }};text-transform:uppercase;letter-spacing:0.5px;">
                                        Order Information
                                    </p>
                                    <p style="margin:0;font-size:13px;color:#4b5563;line-height:1.6;">
                                        Click the button below to view the full order details or contact support if you have any questions.
                                    </p>
                                </div>

                                {{-- Order reference: one row per field, mirroring emails.client-welcome's account-details table --}}
                                @if ($has_order_meta)
                                    <div style="box-sizing:border-box;padding:0;color:#374151;margin:0 0 28px;">
                                        <table cellpadding="8" cellspacing="0"
                                            style="margin:0;box-sizing:border-box;width:100%;background-color:#fdf2f8;border-radius:4px;">
                                            @foreach ($meta_fields as $index => $field)
                                                <tr>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:left;font-weight:500;color:#c084a8;width:35%;{{ $index < count($meta_fields) - 1 ? 'border-bottom:1px dashed #f3d9e8;' : '' }}">
                                                        {{ $field['label'] }}
                                                    </td>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:right;{{ $index < count($meta_fields) - 1 ? 'border-bottom:1px dashed #f3d9e8;' : '' }}">
                                                        {{ $field['value'] }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </div>
                                @endif

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:0 0 10px;">
                                    <a href="{{ $order_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Order
                                    </a>
                                </div>

                                {{-- Footer note --}}
                                <p style="margin:24px 0 0;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this email because you have an active order with {{ $app_name }}.
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
