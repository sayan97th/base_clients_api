<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply to your comment — {{ config('app.name') }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';
        $app_name    = config('app.name');
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
                                        NEW REPLY
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    The {{ $app_name }} team replied
                                </h1>

                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    {{ $order_title }}
                                </p>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $client_name }}</strong>,
                                </p>

                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    <strong>{{ $admin_name }}</strong> from the {{ $app_name }} team has replied to your comment on order <strong>{{ $order_title }}</strong>.
                                </p>

                                {{-- Original comment (only shown when there is one) --}}
                                @if ($original_comment_content !== '')
                                    <p style="margin:0 0 8px;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">
                                        Your original comment
                                    </p>
                                    <div style="box-sizing:border-box;background-color:#f9fafb;border-left:3px solid #d1d5db;border-radius:4px;padding:14px 18px;margin:0 0 20px;">
                                        <p style="margin:0 0 4px;font-size:11px;color:#9ca3af;">{{ $original_comment_date }}</p>
                                        <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.6;white-space:pre-line;">{{ $original_comment_content }}</p>
                                    </div>
                                @endif

                                {{-- Reply box --}}
                                <p style="margin:0 0 8px;font-size:12px;font-weight:600;color:{{ $brand_color }};text-transform:uppercase;letter-spacing:0.5px;">
                                    Reply from {{ $admin_name }}
                                </p>
                                <div style="box-sizing:border-box;background-color:#fdf2f8;border-left:3px solid {{ $brand_color }};border-radius:4px;padding:16px 20px;margin:0 0 28px;">
                                    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
                                        <tr>
                                            <td width="36" style="vertical-align:middle;padding-right:10px;">
                                                <div style="width:32px;height:32px;border-radius:50%;background-color:{{ $brand_color }};color:#ffffff;font-size:13px;font-weight:700;text-align:center;line-height:32px;">
                                                    {{ $admin_initials }}
                                                </div>
                                            </td>
                                            <td style="vertical-align:middle;">
                                                <p style="margin:0;font-size:13px;font-weight:600;color:#111827;">{{ $admin_name }}</p>
                                                <p style="margin:0;font-size:11px;color:#9ca3af;">{{ $reply_date }}</p>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;white-space:pre-line;">{{ $reply_content }}</p>
                                </div>

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:0 0 24px;">
                                    <a href="{{ $view_reply_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Reply
                                    </a>
                                </div>

                                {{-- Footer note --}}
                                <p style="margin:0;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this email because you have an active order with {{ $app_name }}.
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
                            Sent to {{ $client_email }}
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
