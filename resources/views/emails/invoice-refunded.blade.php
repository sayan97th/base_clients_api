<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $is_full_refund ? 'Refund' : 'Partial Refund' }} — {{ $invoice_number }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color  = '#ec3c89';
        $brand_bg     = '#fce7f3';
        $accent_color = '#2563eb';
        $accent_bg    = '#dbeafe';
        $app_name     = config('app.name');
        $badge_label  = $is_full_refund ? 'REFUND ISSUED' : 'PARTIAL REFUND';
        $headline     = $is_full_refund ? 'Your refund has been processed' : 'A partial refund has been processed';
        $has_breakdown = $credit_refund && $card_refund;
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
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:{{ $accent_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0 40px 36px;">

                                {{-- Badge --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $accent_bg }};color:{{ $accent_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        {{ $badge_label }}
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    {{ $headline }}
                                </h1>
                                <p align="center"
                                    style="margin:0 0 24px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    Invoice #{{ $invoice_number }}
                                </p>

                                {{-- Refund amount hero --}}
                                <div style="text-align:center;margin:0 0 28px;">
                                    <p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">
                                        Amount Refunded
                                    </p>
                                    <p style="margin:0;font-size:34px;font-weight:700;color:{{ $accent_color }};line-height:1.1;">
                                        {{ $refund_amount }}
                                    </p>
                                </div>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    @if ($recipient_name)
                                        Hi <strong>{{ $recipient_name }}</strong>,
                                    @else
                                        Hi there,
                                    @endif
                                </p>

                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    @if ($is_full_refund)
                                        We've processed a full refund of <strong>{{ $refund_amount }}</strong> for invoice
                                        <strong>#{{ $invoice_number }}</strong>. The details are summarized below for your records.
                                    @else
                                        We've processed a partial refund of <strong>{{ $refund_amount }}</strong> for invoice
                                        <strong>#{{ $invoice_number }}</strong>. The details are summarized below for your records.
                                    @endif
                                </p>

                                {{-- Refund details --}}
                                <table cellpadding="0" cellspacing="0" border="0" width="100%"
                                    style="margin:0 0 20px;background-color:#f9fafb;border-radius:6px;overflow:hidden;">
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Invoice</td>
                                                    <td style="font-size:14px;color:#111827;font-weight:500;">#{{ $invoice_number }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Refund Amount</td>
                                                    <td style="font-size:15px;color:{{ $accent_color }};font-weight:700;">{{ $refund_amount }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    @if ($has_breakdown)
                                        <tr>
                                            <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                                <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                    <tr>
                                                        <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Returned to Balance</td>
                                                        <td style="font-size:14px;color:#7c3aed;font-weight:600;">{{ $credit_refund }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                                <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                    <tr>
                                                        <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Refunded to Card</td>
                                                        <td style="font-size:14px;color:#111827;font-weight:600;">{{ $card_refund }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endif
                                    @unless ($is_full_refund)
                                        <tr>
                                            <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                                <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                    <tr>
                                                        <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Invoice Total</td>
                                                        <td style="font-size:14px;color:#374151;">{{ $total_amount }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                                <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                    <tr>
                                                        <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Total Refunded</td>
                                                        <td style="font-size:14px;color:#374151;">{{ $total_refunded }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endunless
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Payment Method</td>
                                                    <td style="font-size:14px;color:#374151;">{{ $payment_method }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">Date Refunded</td>
                                                    <td style="font-size:13px;color:#374151;">{{ $refund_date }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Timing note --}}
                                @if ($card_refund)
                                    <div style="margin:0 0 24px;padding:14px 18px;background-color:{{ $accent_bg }};border-radius:6px;">
                                        <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;">
                                            Card refunds typically take <strong>5&ndash;10 business days</strong> to appear on
                                            your statement, depending on your bank or card issuer.
                                        </p>
                                    </div>
                                @endif

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:0 0 24px;">
                                    <a href="{{ $invoice_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $accent_color }};padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Invoice
                                    </a>
                                </div>

                                {{-- Support note --}}
                                <p style="margin:0;font-weight:normal;font-size:13px;color:#6b7280;text-align:center;line-height:1.6;">
                                    Have a question about this refund?
                                    @if ($support_email)
                                        Reach out to us at
                                        <a href="mailto:{{ $support_email }}" style="color:{{ $brand_color }};text-decoration:none;">{{ $support_email }}</a>.
                                    @else
                                        Just reply to this email and we'll be happy to help.
                                    @endif
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <div style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#9ca3af;padding:20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">
                            &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                        </p>
                        @if ($recipient_email)
                            <p style="margin:0;font-size:11px;color:#d1d5db;">
                                Sent to {{ $recipient_email }}
                            </p>
                        @endif
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
