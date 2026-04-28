<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Report — {{ $app_name }}</title>
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

        $report_badge = $report_badge ?? 'LINK BUILDING REPORT';
        $purchase_summary = $purchase_summary ?? [];
    @endphp

    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:0;box-sizing:border-box;width:100%;background-color:#f9f0f5;">
        <tr>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
            <td width="680"
                style="box-sizing:border-box;vertical-align:top;display:block;max-width:680px;margin:0 auto;clear:both;">
                <div style="box-sizing:border-box;max-width:680px;margin:0 auto;display:block;padding:24px;">

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
                                        {{ $report_badge }}
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    Your Report Is Ready
                                </h1>

                                {{-- Sub-heading --}}
                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    {{ $order->order_title }}
                                </p>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $user_name }}</strong>,
                                </p>

                                {{-- Intro --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    We have prepared your link building report. Please find the delivery details for each of your placements below.
                                </p>

                                {{-- Custom message --}}
                                @if ($custom_message)
                                    <div style="box-sizing:border-box;background-color:#fdf2f8;border-left:3px solid {{ $brand_color }};border-radius:4px;padding:14px 18px;margin:0 0 24px;">
                                        <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;">
                                            {{ $custom_message }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Tables (virtual — derived from order items) --}}
                                @forelse ($report_data['tables'] as $table)
                                    {{-- Table title --}}
                                    <div style="margin:0 0 10px;">
                                        <p style="margin:0;font-size:15px;font-weight:700;color:#111827;">
                                            {{ $table['title'] }}
                                        </p>
                                        @if (!empty($table['description']))
                                            <p style="margin:4px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">
                                                {{ $table['description'] }}
                                            </p>
                                        @endif
                                    </div>

                                    @if (!empty($table['rows']))
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 28px;border-collapse:collapse;font-size:12px;width:100%;">
                                            <thead>
                                                <tr style="background-color:#fdf2f8;">
                                                    <th style="padding:8px 10px;text-align:left;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;white-space:nowrap;">Order #</th>
                                                    <th style="padding:8px 10px;text-align:left;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;">Keyword</th>
                                                    <th style="padding:8px 10px;text-align:left;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;">Landing Page</th>
                                                    <th style="padding:8px 10px;text-align:center;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;white-space:nowrap;">Exact Match</th>
                                                    <th style="padding:8px 10px;text-align:center;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;white-space:nowrap;">Status</th>
                                                    <th style="padding:8px 10px;text-align:left;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;white-space:nowrap;">Live Link</th>
                                                    <th style="padding:8px 10px;text-align:center;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;white-space:nowrap;">DR</th>
                                                    <th style="padding:8px 10px;text-align:center;color:{{ $brand_color }};font-weight:700;border-bottom:2px solid #f3e8ef;white-space:nowrap;">Live Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($table['rows'] as $index => $row)
                                                    @php
                                                        $row_bg = $index % 2 === 0 ? '#ffffff' : '#fdf9fb';
                                                        $status_color = match($row['status']) {
                                                            'live'     => '#059669',
                                                            'rejected' => '#dc2626',
                                                            default    => '#d97706',
                                                        };
                                                        $status_bg = match($row['status']) {
                                                            'live'     => '#d1fae5',
                                                            'rejected' => '#fee2e2',
                                                            default    => '#fef3c7',
                                                        };
                                                    @endphp
                                                    <tr style="background-color:{{ $row_bg }};">
                                                        <td style="padding:8px 10px;color:#374151;border-bottom:1px solid #f3e8ef;white-space:nowrap;">{{ $row['order_number'] }}</td>
                                                        <td style="padding:8px 10px;color:#374151;border-bottom:1px solid #f3e8ef;">{{ $row['keyword'] }}</td>
                                                        <td style="padding:8px 10px;border-bottom:1px solid #f3e8ef;max-width:130px;overflow:hidden;">
                                                            <a href="{{ $row['landing_page'] }}" style="color:{{ $brand_color }};text-decoration:none;font-size:11px;" target="_blank">
                                                                {{ $row['landing_page'] }}
                                                            </a>
                                                        </td>
                                                        <td style="padding:8px 10px;text-align:center;color:#374151;border-bottom:1px solid #f3e8ef;">
                                                            {{ $row['exact_match'] ? 'Yes' : 'No' }}
                                                        </td>
                                                        <td style="padding:8px 10px;text-align:center;border-bottom:1px solid #f3e8ef;">
                                                            <span style="display:inline-block;background-color:{{ $status_bg }};color:{{ $status_color }};font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;">
                                                                {{ $row['status'] }}
                                                            </span>
                                                        </td>
                                                        <td style="padding:8px 10px;border-bottom:1px solid #f3e8ef;max-width:130px;overflow:hidden;">
                                                            @if ($row['live_link'])
                                                                <a href="{{ $row['live_link'] }}" style="color:{{ $brand_color }};text-decoration:none;font-size:11px;" target="_blank">
                                                                    {{ $row['live_link'] }}
                                                                </a>
                                                            @else
                                                                <span style="color:#9ca3af;">—</span>
                                                            @endif
                                                        </td>
                                                        <td style="padding:8px 10px;text-align:center;color:#374151;border-bottom:1px solid #f3e8ef;">
                                                            {{ $row['dr'] ?? '—' }}
                                                        </td>
                                                        <td style="padding:8px 10px;text-align:center;color:#374151;border-bottom:1px solid #f3e8ef;white-space:nowrap;">
                                                            {{ $row['live_link_date'] ?? '—' }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @else
                                        <p style="margin:0 0 24px;font-size:13px;color:#9ca3af;font-style:italic;">
                                            No placements in this group yet.
                                        </p>
                                    @endif

                                @empty
                                    <p style="margin:0 0 24px;font-size:14px;color:#6b7280;">
                                        No placement data is available for this order yet.
                                    </p>
                                @endforelse

                                {{-- ── Purchase Overview (multi-purchase context) ── --}}
                                @if (!empty($purchase_summary))
                                    <div style="margin:8px 0 28px;padding:16px 20px;background-color:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
                                        <p style="margin:0 0 12px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">
                                            Other Items in Your Purchase
                                        </p>
                                        @foreach ($purchase_summary as $summary_item)
                                            @php
                                                $s_cat   = $summary_item['category'] ?? 'link_building';
                                                $s_color = $category_colors[$s_cat] ?? $brand_color;
                                                $s_bg    = $category_bgs[$s_cat] ?? $brand_bg;
                                                $s_label = $category_labels[$s_cat] ?? $s_cat;
                                            @endphp
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                                style="margin:0 0 8px;border-bottom:1px solid #e5e7eb;">
                                                <tr>
                                                    <td style="padding:8px 0;vertical-align:middle;white-space:nowrap;width:1%;">
                                                        <span style="display:inline-block;background-color:{{ $s_bg }};color:{{ $s_color }};font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;white-space:nowrap;margin-right:10px;">
                                                            {{ $s_label }}
                                                        </span>
                                                    </td>
                                                    <td style="padding:8px 0;font-size:13px;color:#374151;vertical-align:middle;">
                                                        {{ $summary_item['title'] }}
                                                    </td>
                                                    @if (!empty($summary_item['status']))
                                                        <td style="padding:8px 0;font-size:12px;color:#6b7280;vertical-align:middle;text-align:right;white-space:nowrap;">
                                                            {{ $summary_item['status'] }}
                                                        </td>
                                                    @endif
                                                </tr>
                                            </table>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:8px 0 10px;">
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
