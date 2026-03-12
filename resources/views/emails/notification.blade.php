<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        @if ($notification_type === 'payment')
            Payment Notification
        @elseif ($notification_type === 'post')
            New Post Update
        @elseif ($notification_type === 'order')
            Order Update
        @else
            System Notification
        @endif
        — {{ $app_name }}
    </title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f4f4f7;width:100%;">

    {{-- Outer wrapper --}}
    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:0;box-sizing:border-box;width:100%;background-color:#f4f4f7;">
        <tr>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
            <td width="600"
                style="box-sizing:border-box;vertical-align:top;display:block;max-width:600px;margin:0 auto;clear:both;">
                <div style="box-sizing:border-box;max-width:600px;margin:0 auto;display:block;padding:24px;">

                    {{-- ── Logo ──────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                        <a href="{{ config('app.frontend_url') }}" style="color:#007bff;text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- ── Type banner ────────────────────────────────────── --}}
                    @php
                        $type_config = [
                            'payment' => ['color' => '#0ea5e9', 'bg' => '#e0f2fe', 'label' => 'PAYMENT',  'icon' => '💳'],
                            'post'    => ['color' => '#8b5cf6', 'bg' => '#ede9fe', 'label' => 'POST',     'icon' => '📄'],
                            'order'   => ['color' => '#f59e0b', 'bg' => '#fef3c7', 'label' => 'ORDER',    'icon' => '🛒'],
                            'system'  => ['color' => '#64748b', 'bg' => '#f1f5f9', 'label' => 'SYSTEM',   'icon' => '🔔'],
                        ];
                        $cfg = $type_config[$notification_type] ?? $type_config['system'];
                    @endphp

                    {{-- ── Main card ──────────────────────────────────────── --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:{{ $cfg['color'] }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0 40px 36px;">

                                {{-- Type badge row --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $cfg['bg'] }};color:{{ $cfg['color'] }};font-size:11px;font-weight:700;letter-spacing:1px;padding:4px 14px;border-radius:20px;">
                                        {{ $cfg['icon'] }}&nbsp;&nbsp;{{ $cfg['label'] }}
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    @if ($notification_type === 'payment')
                                        Payment Notification
                                    @elseif ($notification_type === 'post')
                                        New Post Update
                                    @elseif ($notification_type === 'order')
                                        Order Update
                                    @else
                                        System Notification
                                    @endif
                                </h1>

                                {{-- Sub-heading --}}
                                <p align="center" style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    @if ($notification_type === 'payment')
                                        A payment event requires your attention.
                                    @elseif ($notification_type === 'post')
                                        New content has been published for you.
                                    @elseif ($notification_type === 'order')
                                        There has been an update to one of your orders.
                                    @else
                                        An important update from {{ $app_name }}.
                                    @endif
                                </p>

                                {{-- Divider --}}
                                <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $user_name }}</strong>,
                                </p>

                                {{-- Main message --}}
                                <p style="margin:0 0 20px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    {{ $notification_message }}
                                </p>

                                {{-- Preview / detail block --}}
                                @if ($preview_text)
                                    <div style="box-sizing:border-box;padding:16px 20px;color:#1f2937;margin:0 0 24px;background-color:{{ $cfg['bg'] }};border-radius:6px;border-left:4px solid {{ $cfg['color'] }};">
                                        <p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:0.5px;color:{{ $cfg['color'] }};text-transform:uppercase;">
                                            Details
                                        </p>
                                        <p style="margin:0;font-weight:normal;font-size:14px;color:#374151;line-height:1.6;">
                                            {{ $preview_text }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Per-type contextual section ──────────────── --}}
                                @if ($notification_type === 'payment')
                                    <div style="box-sizing:border-box;background-color:#f8fafc;border-radius:6px;padding:16px 20px;margin:0 0 24px;">
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#0ea5e9;text-transform:uppercase;letter-spacing:0.5px;">
                                            Payment Information
                                        </p>
                                        <p style="margin:0;font-size:13px;color:#4b5563;line-height:1.6;">
                                            Please review the payment details and take action if needed.
                                            If you believe this notification was sent in error, you can safely ignore it.
                                            Your account security is important to us — never share your payment credentials.
                                        </p>
                                    </div>

                                @elseif ($notification_type === 'post')
                                    <div style="box-sizing:border-box;background-color:#f8fafc;border-radius:6px;padding:16px 20px;margin:0 0 24px;">
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#8b5cf6;text-transform:uppercase;letter-spacing:0.5px;">
                                            Content Update
                                        </p>
                                        <p style="margin:0;font-size:13px;color:#4b5563;line-height:1.6;">
                                            New content is ready for you on the platform.
                                            Click the button below to read the full post and stay up to date.
                                        </p>
                                    </div>

                                @elseif ($notification_type === 'order')
                                    <div style="box-sizing:border-box;background-color:#f8fafc;border-radius:6px;padding:16px 20px;margin:0 0 24px;">
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:0.5px;">
                                            Order Information
                                        </p>
                                        <p style="margin:0;font-size:13px;color:#4b5563;line-height:1.6;">
                                            There has been a status change on one of your orders.
                                            Click below to view the full order details, track shipment, or contact support if needed.
                                        </p>
                                    </div>

                                @else
                                    <div style="box-sizing:border-box;background-color:#f8fafc;border-radius:6px;padding:16px 20px;margin:0 0 24px;">
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">
                                            System Information
                                        </p>
                                        <p style="margin:0;font-size:13px;color:#4b5563;line-height:1.6;">
                                            This is an automated system notification from {{ $app_name }}.
                                            No action is required unless specified above.
                                        </p>
                                    </div>
                                @endif

                                {{-- Notification metadata ──────────────────── --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 28px;background-color:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
                                    <tr>
                                        <td style="padding:14px 20px;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">
                                                Received
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;">
                                                {{ $notification_date }}
                                                <span style="color:#9ca3af;">({{ $notification_relative }})</span>
                                            </p>
                                        </td>
                                        <td style="padding:14px 20px;border-left:1px solid #e5e7eb;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">
                                                Type
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;text-transform:capitalize;">
                                                {{ $notification_type }}
                                            </p>
                                        </td>
                                        <td style="padding:14px 20px;border-left:1px solid #e5e7eb;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">
                                                Ref #
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;">
                                                {{ str_pad($notification_id, 6, '0', STR_PAD_LEFT) }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                {{-- CTA button ──────────────────────────────── --}}
                                @if ($action_url)
                                    <div style="box-sizing:border-box;text-align:center;margin:0 0 10px;">
                                        <a href="{{ $action_url }}"
                                            style="text-decoration:none;color:#ffffff;background-color:{{ $cfg['color'] }};padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                            target="_blank">
                                            @if ($notification_type === 'payment')
                                                Review Payment
                                            @elseif ($notification_type === 'post')
                                                Read Post
                                            @elseif ($notification_type === 'order')
                                                View Order
                                            @else
                                                View Details
                                            @endif
                                        </a>
                                    </div>
                                @endif

                                {{-- Preferences note ────────────────────────── --}}
                                <p style="margin:24px 0 0;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this email because your notification preferences are set to
                                    <strong>Email &amp; Portal</strong>.<br>
                                    <a href="{{ $preferences_url }}"
                                        style="color:#ec3c89;text-decoration:none;">
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
