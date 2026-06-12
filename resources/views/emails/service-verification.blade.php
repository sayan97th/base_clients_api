<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Service Verification — {{ $app_name }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';
        $success_color = '#059669';
        $success_bg    = '#d1fae5';
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
                        <a href="{{ $app_url }}"
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

                                {{-- Badge --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        SERVICE CHECK
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    Email Delivery Verification
                                </h1>

                                {{-- Sub-heading --}}
                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    Confirming that the email service is operational and delivering correctly.
                                </p>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $recipient_name }}</strong>,
                                </p>

                                {{-- Main message --}}
                                <p style="margin:0 0 20px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    This is an automated verification email sent from <strong>{{ $app_name }}</strong>
                                    to confirm that the email delivery service is working correctly.
                                    If you are reading this, the email was delivered successfully.
                                </p>

                                {{-- Success status block --}}
                                <div style="box-sizing:border-box;padding:16px 20px;color:#065f46;margin:0 0 24px;background-color:{{ $success_bg }};border-radius:6px;border-left:4px solid {{ $success_color }};">
                                    <p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:0.5px;color:{{ $success_color }};text-transform:uppercase;">
                                        Delivery Confirmed
                                    </p>
                                    <p style="margin:0;font-weight:normal;font-size:14px;color:#065f46;line-height:1.6;">
                                        The email service is <strong>fully operational</strong>.
                                        Messages sent via <strong>{{ strtoupper($mailer_name) }}</strong> are being received without issues.
                                    </p>
                                </div>

                                {{-- Service diagnostics --}}
                                <div style="box-sizing:border-box;background-color:#fdf2f8;border-radius:6px;padding:16px 20px;margin:0 0 24px;">
                                    <p style="margin:0 0 10px;font-size:12px;font-weight:700;color:{{ $brand_color }};text-transform:uppercase;letter-spacing:0.5px;">
                                        Service Diagnostics
                                    </p>

                                    @foreach ($service_info as $label => $value)
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 4px;">
                                            <tr>
                                                <td width="40%" style="font-size:12px;color:#9ca3af;font-weight:600;vertical-align:top;padding:3px 0;">
                                                    {{ $label }}
                                                </td>
                                                <td style="font-size:13px;color:#374151;vertical-align:top;padding:3px 0;">
                                                    {{ $value }}
                                                </td>
                                            </tr>
                                        </table>
                                    @endforeach
                                </div>

                                {{-- Token / reference block --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 28px;background-color:#fdf2f8;border-radius:6px;border:1px solid #f3e8ef;">
                                    <tr>
                                        <td style="padding:14px 20px;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Verification Token
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;font-family:monospace;letter-spacing:0.5px;">
                                                {{ $verification_token }}
                                            </p>
                                        </td>
                                        <td style="padding:14px 20px;border-left:1px solid #f3e8ef;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Sent At
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;">
                                                {{ $sent_at }}
                                            </p>
                                        </td>
                                        <td style="padding:14px 20px;border-left:1px solid #f3e8ef;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Mailer
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;text-transform:capitalize;">
                                                {{ $mailer_name }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Info note --}}
                                <p style="margin:0;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    This is an automated system check email from <strong>{{ $app_name }}</strong>.<br>
                                    No action is required. You can safely discard this message.
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
