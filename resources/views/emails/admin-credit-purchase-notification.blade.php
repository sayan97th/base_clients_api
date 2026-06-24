<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Credit Purchase — {{ $client_name }} — {{ config('app.name') }}</title>
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
                        <a href="{{ $view_purchases_url }}"
                            style="color:{{ $brand_color }};text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- Main card --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:#10b981;border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0 40px 36px;">

                                {{-- Badge --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:#d1fae5;color:#059669;font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        NEW CREDIT PURCHASE
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    A client just purchased credits
                                </h1>

                                @if($recipient_name)
                                    <p align="center"
                                        style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;margin:0 0 24px;font-size:14px;color:#6b7280;">
                                        Hi {{ $recipient_name }}, here are the purchase details.
                                    </p>
                                @else
                                    <p align="center"
                                        style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;margin:0 0 24px;font-size:14px;color:#6b7280;">
                                        Here are the purchase details.
                                    </p>
                                @endif

                                {{-- Client card --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 20px;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                                    <tr>
                                        <td style="padding:16px 20px;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td width="44" style="vertical-align:top;">
                                                        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,{{ $brand_color }},#be185d);display:flex;align-items:center;justify-content:center;text-align:center;line-height:40px;font-size:15px;font-weight:700;color:#ffffff;">
                                                            {{ $client_initials }}
                                                        </div>
                                                    </td>
                                                    <td style="padding-left:12px;vertical-align:top;">
                                                        <p style="margin:0;font-size:15px;font-weight:600;color:#111827;">{{ $client_name }}</p>
                                                        <p style="margin:2px 0 0;font-size:13px;color:#6b7280;">{{ $client_email }}</p>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Purchase details --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                    <tr style="background-color:#f9fafb;border-bottom:1px solid #e5e7eb;">
                                        <td style="padding:10px 20px;font-size:11px;font-weight:700;letter-spacing:1px;color:#6b7280;text-transform:uppercase;">
                                            PURCHASE DETAILS
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:0;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr style="border-bottom:1px solid #f3f4f6;">
                                                    <td style="padding:12px 20px;font-size:13px;color:#6b7280;width:40%;">Package</td>
                                                    <td style="padding:12px 20px;font-size:13px;font-weight:600;color:#111827;text-align:right;">{{ $package_name }}</td>
                                                </tr>
                                                <tr style="border-bottom:1px solid #f3f4f6;">
                                                    <td style="padding:12px 20px;font-size:13px;color:#6b7280;">Credits Purchased</td>
                                                    <td style="padding:12px 20px;font-size:13px;font-weight:700;color:#059669;text-align:right;">
                                                        +{{ number_format($credits_amount) }} CR
                                                    </td>
                                                </tr>
                                                <tr style="border-bottom:1px solid #f3f4f6;">
                                                    <td style="padding:12px 20px;font-size:13px;color:#6b7280;">Amount Paid</td>
                                                    <td style="padding:12px 20px;font-size:14px;font-weight:700;color:#111827;text-align:right;">{{ $amount_paid }}</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding:12px 20px;font-size:13px;color:#6b7280;">Purchase Date</td>
                                                    <td style="padding:12px 20px;font-size:13px;color:#374151;text-align:right;">{{ $purchase_date }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                {{-- CTA button --}}
                                <div style="text-align:center;margin:0 0 28px;">
                                    <a href="{{ $view_purchases_url }}"
                                        style="display:inline-block;background-color:#ec3c89;color:#ffffff;font-size:14px;font-weight:600;padding:12px 32px;border-radius:8px;text-decoration:none;letter-spacing:0.3px;">
                                        View Credit Purchases
                                    </a>
                                </div>

                                {{-- Divider --}}
                                <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 20px;">

                                {{-- Footer note --}}
                                <p style="font-size:12px;color:#9ca3af;text-align:center;margin:0;">
                                    You received this notification because you are listed as a notification recipient in
                                    <a href="{{ $settings_url }}" style="color:{{ $brand_color }};text-decoration:none;">Email Notification Settings</a>.
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <p style="font-size:11px;color:#9ca3af;text-align:center;margin:20px 0 0;padding:0 20px;">
                        &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                    </p>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>

</body>
</html>
