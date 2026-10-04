<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automatic payment update — {{ $invoice_number }} — {{ config('app.name') }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;margin:0;padding:0;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $app_name    = config('app.name');
    @endphp

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9f0f5;">
        <tr>
            <td>&nbsp;</td>
            <td width="600" style="display:block;max-width:600px;margin:0 auto;">
                <div style="max-width:600px;margin:0 auto;padding:24px;">

                    <div style="padding:0 20px 20px;text-align:center;">
                        <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                            alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                    </div>

                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="background-color:#ffffff;border-top:4px solid {{ $brand_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="padding:32px 40px 36px;">

                                <h1 style="line-height:1.25em;color:#111827;margin:0 0 20px;font-size:22px;font-weight:600;">
                                    {{ $is_skipped ? 'Your invoice needs a manual payment' : 'Your automatic payment did not go through' }}
                                </h1>

                                <p style="margin:0 0 16px;color:#374151;font-size:15px;">
                                    Hello <strong>{{ $client_name }}</strong>,
                                </p>

                                <p style="margin:0 0 20px;color:#374151;font-size:15px;line-height:1.6;">
                                    @if ($is_skipped)
                                        Invoice <strong>#{{ $invoice_number }}</strong> for <strong>{{ $amount }}</strong> was not charged automatically.
                                    @else
                                        We tried to charge your card <strong>{{ $card_label }}</strong> for invoice
                                        <strong>#{{ $invoice_number }}</strong> ({{ $amount }}), but the payment was not successful.
                                        You have not been charged.
                                    @endif
                                </p>

                                <div style="background-color:#fef2f2;border-radius:6px;padding:14px 18px;margin:0 0 20px;">
                                    <p style="margin:0;font-size:14px;color:#b91c1c;"><strong>Reason:</strong> {{ $failure_reason }}</p>
                                </div>

                                @if ($next_retry_at)
                                    <p style="margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6;">
                                        We will try again automatically on <strong>{{ $next_retry_at }}</strong>.
                                        To avoid another failed attempt, you can pay now or update your card.
                                    </p>
                                @else
                                    <p style="margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6;">
                                        We will not retry this charge automatically. Please pay the invoice manually.
                                    </p>
                                @endif

                                <div style="text-align:center;margin:0 0 20px;">
                                    <a href="{{ $pay_url }}" target="_blank"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 44px;line-height:28px;font-weight:600;font-size:15px;display:inline-block;border-radius:6px;">
                                        Pay Invoice
                                    </a>
                                </div>

                                <p style="margin:0;font-size:13px;color:#6b7280;text-align:center;">
                                    You can change your autopay card or turn autopay off at any time from
                                    <a href="{{ $manage_autopay_url }}" target="_blank" style="color:{{ $brand_color }};text-decoration:none;">your invoices page</a>.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style="padding:20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">&copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.</p>
                        <p style="margin:0;font-size:11px;color:#d1d5db;">Sent to {{ $client_email }}</p>
                    </div>
                </div>
            </td>
            <td>&nbsp;</td>
        </tr>
    </table>
</body>

</html>
