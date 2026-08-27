<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Support Ticket — {{ config('app.name') }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';
        $app_name    = config('app.name');

        $priority_colors = [
            'high'   => ['bg' => '#fef2f2', 'text' => '#dc2626', 'label' => 'High'],
            'medium' => ['bg' => '#fffbeb', 'text' => '#d97706', 'label' => 'Medium'],
            'low'    => ['bg' => '#eff6ff', 'text' => '#2563eb', 'label' => 'Low'],
        ];
        $p = $priority_colors[$ticket_priority] ?? $priority_colors['medium'];
    @endphp

    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:0;box-sizing:border-box;width:100%;background-color:#f9f0f5;">
        <tr>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
            <td width="600"
                style="box-sizing:border-box;vertical-align:top;display:block;max-width:600px;margin:0 auto;clear:both;">
                <div style="box-sizing:border-box;max-width:600px;margin:0 auto;display:block;padding:24px;">

                    {{-- Logo --}}
                    <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                        <a href="{{ config('app.frontend_url') }}"
                            style="color:{{ $brand_color }};text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- Main card --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:{{ $brand_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0 40px 36px;">

                                {{-- Badge --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        NEW SUPPORT TICKET
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    A client opened a support ticket
                                </h1>

                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    {{ $ticket_number }}
                                </p>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    @if ($recipient_name)
                                        Hello <strong>{{ $recipient_name }}</strong>,
                                    @else
                                        Hello,
                                    @endif
                                </p>

                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    A new support ticket has been submitted and requires your attention.
                                </p>

                                {{-- Client info box --}}
                                <div style="box-sizing:border-box;background-color:#f9fafb;border-radius:6px;padding:16px 20px;margin:0 0 20px;">
                                    <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                        <tr>
                                            <td width="44" style="vertical-align:middle;padding-right:14px;">
                                                <div style="width:40px;height:40px;border-radius:50%;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:16px;font-weight:700;text-align:center;line-height:40px;">
                                                    {{ $client_initials }}
                                                </div>
                                            </td>
                                            <td style="vertical-align:middle;">
                                                <p style="margin:0 0 2px;font-size:14px;font-weight:600;color:#111827;">
                                                    {{ $client_name }}
                                                </p>
                                                <p style="margin:0;font-size:12px;color:#6b7280;">
                                                    {{ $client_email }}
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                {{-- Ticket details --}}
                                <table cellpadding="0" cellspacing="0" border="0" width="100%"
                                    style="margin:0 0 20px;background-color:#f9fafb;border-radius:6px;overflow:hidden;">
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:90px;">Subject</td>
                                                    <td style="font-size:14px;color:#111827;font-weight:500;">{{ $ticket_subject }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:90px;">Priority</td>
                                                    <td>
                                                        <span style="display:inline-block;background-color:{{ $p['bg'] }};color:{{ $p['text'] }};font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px;">
                                                            {{ $p['label'] }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:90px;">Opened</td>
                                                    <td style="font-size:13px;color:#374151;">{{ $ticket_date }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Initial message --}}
                                <div style="box-sizing:border-box;background-color:#fdf2f8;border-left:3px solid {{ $brand_color }};border-radius:4px;padding:16px 20px;margin:0 0 28px;">
                                    <p style="margin:0 0 6px;font-size:11px;font-weight:700;color:{{ $brand_color }};text-transform:uppercase;letter-spacing:0.5px;">
                                        Client's Message
                                    </p>
                                    <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;white-space:pre-line;">{{ $initial_message }}</p>
                                </div>

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:0 0 24px;">
                                    <a href="{{ $view_ticket_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Ticket
                                    </a>
                                </div>

                                {{-- Footer note --}}
                                <p style="margin:0 0 8px;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this notification because you are on the support ticket alert list.
                                    <a href="{{ $settings_url }}" style="color:{{ $brand_color }};text-decoration:none;" target="_blank">Manage notification settings</a>
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <div style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#9ca3af;padding:20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">
                            &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                        </p>
                        <p style="margin:0;font-size:11px;color:#d1d5db;">
                            Sent to {{ $recipient_email }}
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
