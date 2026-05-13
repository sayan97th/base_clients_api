<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits Added — {{ $app_name }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';
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
                        <a href="{{ $frontend_url }}"
                            style="color:{{ $brand_color }};text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- Main card --}}
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
                                    Credits Added!
                                </h1>
                                <p align="center"
                                    style="margin:0 0 24px;font-weight:normal;font-size:15px;color:#6b7280;line-height:1.6;">
                                    Your purchase was successful. Your credits are ready to use.
                                </p>

                                <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 28px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6;">
                                    Hi {{ $user_first_name }} {{ $user_last_name }},
                                </p>
                                <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
                                    Thank you for your purchase! <strong>{{ number_format($credits_amount) }} credits</strong>
                                    have been added to your account and are available immediately.
                                </p>

                                {{-- Purchase summary card --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 28px;background-color:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
                                    <tr>
                                        <td style="padding:20px 24px;">
                                            <p style="margin:0 0 4px;font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;">
                                                Purchase Summary
                                            </p>
                                            <p style="margin:0 0 20px;font-size:16px;font-weight:600;color:#111827;">
                                                {{ $package_name }}
                                            </p>

                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="padding:6px 0;font-size:14px;color:#6b7280;">Credits added</td>
                                                    <td align="right" style="padding:6px 0;font-size:14px;font-weight:600;color:#10b981;">
                                                        +{{ number_format($credits_amount) }} credits
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="padding:6px 0;font-size:14px;color:#6b7280;">Amount charged</td>
                                                    <td align="right" style="padding:6px 0;font-size:14px;color:#374151;">
                                                        ${{ number_format($amount_paid, 2) }} USD
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="padding:6px 0;font-size:14px;color:#6b7280;">Purchase date</td>
                                                    <td align="right" style="padding:6px 0;font-size:14px;color:#374151;">
                                                        {{ $purchase_date }}
                                                    </td>
                                                </tr>
                                            </table>

                                            <hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;">

                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-size:15px;font-weight:600;color:#111827;">New credit balance</td>
                                                    <td align="right"
                                                        style="font-size:20px;font-weight:700;color:{{ $brand_color }};">
                                                        {{ number_format($new_balance) }} credits
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Credits never expire notice --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 28px;background-color:{{ $brand_bg }};border-radius:6px;border:1px solid #f9a8d4;">
                                    <tr>
                                        <td style="padding:16px 20px;">
                                            <p style="margin:0;font-size:14px;color:#9d174d;line-height:1.6;">
                                                <strong>&#128197; Your credits never expire.</strong>
                                                Use them at your own pace — they will always be available in your account.
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                {{-- CTA button --}}
                                <div style="text-align:center;margin:0 0 28px;">
                                    <a href="{{ $frontend_url }}"
                                        style="display:inline-block;background-color:{{ $brand_color }};color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:14px 32px;border-radius:6px;letter-spacing:0.01em;">
                                        Go to Dashboard
                                    </a>
                                </div>

                                <p style="margin:0 0 8px;font-size:14px;color:#6b7280;line-height:1.6;">
                                    Thank you for choosing {{ $app_name }}. If you have any questions about your purchase,
                                    please contact our support team.
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <div style="text-align:center;padding:20px 0;color:#9ca3af;font-size:12px;line-height:1.6;">
                        <p style="margin:0 0 4px;">{{ $app_name }}</p>
                        <p style="margin:0;">You are receiving this email because a credit purchase was made on your account.</p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>

</body>
</html>
