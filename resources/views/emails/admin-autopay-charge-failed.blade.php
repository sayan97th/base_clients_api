<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $is_skipped ? 'Autopay Skipped' : 'Autopay Payment Failed' }} — {{ $invoice_number }} — {{ config('app.name') }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;margin:0;padding:0;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color  = '#ec3c89';
        $accent_color = $is_skipped ? '#d97706' : '#dc2626';
        $accent_bg    = $is_skipped ? '#fef3c7' : '#fee2e2';
        $app_name     = config('app.name');
        $rows = [
            'Invoice'        => '#' . $invoice_number,
            'Client'         => $client_name . ($client_email ? ' (' . $client_email . ')' : ''),
            'Amount'         => $amount,
            'Card'           => $card_label,
            'Attempt'        => $attempt_label,
            'Reason'         => $failure_reason . ($failure_code ? ' [' . $failure_code . ']' : ''),
            'Next Retry'     => $next_retry_at ?? 'No automatic retry — manual action required',
        ];
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
                        style="background-color:#ffffff;border-top:4px solid {{ $accent_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="padding:0 40px 36px;">

                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $accent_bg }};color:{{ $accent_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        {{ $is_skipped ? 'AUTOPAY SKIPPED' : 'AUTOPAY PAYMENT FAILED' }}
                                    </span>
                                </div>

                                <h1 align="center" style="line-height:1.25em;color:#111827;margin:0 0 6px;font-size:22px;font-weight:600;">
                                    {{ $is_skipped ? 'An invoice was not charged automatically' : 'An automatic card charge failed' }}
                                </h1>

                                <p align="center" style="margin:0 0 28px;color:#6b7280;font-size:13px;">
                                    Invoice #{{ $invoice_number }}
                                </p>

                                <p style="margin:0 0 16px;color:#374151;font-size:15px;">
                                    Hello{{ $recipient_name ? ' ' : '' }}<strong>{{ $recipient_name }}</strong>,
                                </p>

                                <p style="margin:0 0 24px;color:#374151;font-size:15px;line-height:1.6;">
                                    @if ($is_skipped)
                                        Autopay did not charge this invoice. No money was taken from the client's card.
                                    @else
                                        Autopay tried to charge the client's saved card, but the payment did not go through.
                                        No money was taken. The client has also been notified by email.
                                    @endif
                                </p>

                                <table cellpadding="0" cellspacing="0" border="0" width="100%"
                                    style="margin:0 0 24px;background-color:#f9fafb;border-radius:6px;overflow:hidden;">
                                    @foreach ($rows as $label => $value)
                                        <tr>
                                            <td style="padding:12px 20px;border-bottom:1px solid #f3f4f6;font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:120px;vertical-align:top;">{{ $label }}</td>
                                            <td style="padding:12px 20px;border-bottom:1px solid #f3f4f6;font-size:14px;color:{{ $label === 'Reason' ? $accent_color : '#111827' }};font-weight:{{ $label === 'Reason' ? '600' : '500' }};">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </table>

                                <div style="text-align:center;margin:0 0 16px;">
                                    <a href="{{ $view_invoice_url }}" target="_blank"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 40px;line-height:28px;font-weight:600;font-size:15px;display:inline-block;border-radius:6px;">
                                        View Invoice
                                    </a>
                                </div>

                                <p style="margin:0 0 24px;text-align:center;font-size:13px;">
                                    <a href="{{ $autopay_dashboard_url }}" target="_blank" style="color:{{ $brand_color }};text-decoration:none;">Open the Autopay activity log</a>
                                </p>

                                <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this notification because you are on the payment alert list.
                                    <a href="{{ $settings_url }}" style="color:{{ $brand_color }};text-decoration:none;" target="_blank">Manage notification settings</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style="padding:20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">&copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.</p>
                        <p style="margin:0;font-size:11px;color:#d1d5db;">Sent to {{ $recipient_email }}</p>
                    </div>
                </div>
            </td>
            <td>&nbsp;</td>
        </tr>
    </table>
</body>

</html>
