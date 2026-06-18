<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Received — {{ $invoice_number }} — {{ config('app.name') }}</title>
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
                        <a href="{{ $view_invoice_url }}"
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
                                        PAYMENT RECEIVED
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    An invoice has been paid
                                </h1>

                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    Invoice #{{ $invoice_number }}
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
                                    A client has paid their invoice via the public payment link. The invoice has been marked as <strong style="color:#059669;">paid</strong> in the system.
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
                                                @if ($client_email)
                                                    <p style="margin:0;font-size:12px;color:#6b7280;">
                                                        {{ $client_email }}
                                                    </p>
                                                @endif
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                {{-- Payment details --}}
                                <table cellpadding="0" cellspacing="0" border="0" width="100%"
                                    style="margin:0 0 20px;background-color:#f9fafb;border-radius:6px;overflow:hidden;">
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:130px;">Invoice</td>
                                                    <td style="font-size:14px;color:#111827;font-weight:500;">#{{ $invoice_number }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:130px;">Amount Paid</td>
                                                    <td style="font-size:15px;color:#059669;font-weight:700;">{{ $total_amount }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3f4f6;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:130px;">Payment Method</td>
                                                    <td style="font-size:14px;color:#374151;">{{ $payment_method }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                                <tr>
                                                    <td style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:130px;">Date Paid</td>
                                                    <td style="font-size:13px;color:#374151;">{{ $payment_date }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Line items --}}
                                @if (!empty($line_items))
                                    <div style="margin:0 0 24px;">
                                        <p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">
                                            Items
                                        </p>
                                        <table cellpadding="0" cellspacing="0" border="0" width="100%"
                                            style="background-color:#fdf2f8;border-radius:6px;overflow:hidden;">
                                            @foreach ($line_items as $item)
                                                <tr>
                                                    <td style="padding:10px 16px;border-bottom:1px solid #fce7f3;font-size:13px;color:#374151;">
                                                        {{ $item['name'] }}
                                                        <span style="color:#9ca3af;">&times; {{ $item['quantity'] }}</span>
                                                    </td>
                                                    <td style="padding:10px 16px;border-bottom:1px solid #fce7f3;font-size:13px;color:#111827;font-weight:600;text-align:right;white-space:nowrap;">
                                                        {{ $item['item_total'] }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </div>
                                @endif

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:0 0 24px;">
                                    <a href="{{ $view_invoice_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:#059669;padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Invoice
                                    </a>
                                </div>

                                {{-- Footer note --}}
                                <p style="margin:0 0 8px;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    You received this notification because you are on the payment alert list.
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
