<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice_number }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f9f0f5;width:100%;">

    @php
        $brand_color = '#ec3c89';
        $is_credits  = ($currency_type ?? 'usd') === 'credits';
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
                                alt="BASE Search Marketing" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- Main card --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:{{ $brand_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:30px 40px 36px;">

                                <p style="margin:0 0 10px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hey <strong>{{ $user_name }}</strong>,
                                </p>
                                <p style="margin:0 0 24px;font-weight:normal;color:#374151;font-size:15px;">
                                    A new invoice has been issued for your account. Please review the details below.
                                </p>

                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#111827;display:block;margin:24px 0 5px;padding:0;font-size:22px;font-weight:600;">
                                    Invoice
                                </h1>
                                <p align="center"
                                    style="margin:0 0 4px;font-weight:normal;font-size:13px;color:#9ca3af;">
                                    Reference {{ $invoice_number }}
                                </p>
                                @if ($date_due)
                                    <p align="center"
                                        style="margin:0 0 20px;font-weight:normal;font-size:13px;color:#9ca3af;">
                                        Due {{ $date_due }}
                                    </p>
                                @endif

                                {{-- Line items --}}
                                <div style="box-sizing:border-box;color:#374151;margin:24px 0 0;">
                                    <table cellpadding="5" cellspacing="0" width="100%"
                                        style="margin:0;box-sizing:border-box;width:100%;">
                                        <tbody>
                                            @foreach ($line_items as $item)
                                                <tr>
                                                    <td align="left"
                                                        style="margin:0;box-sizing:border-box;vertical-align:top;text-align:left;border-top:1px dashed #e5e7eb;padding:8px 4px;">
                                                        <a href="{{ $invoice_url }}"
                                                            style="color:{{ $brand_color }};text-decoration:none;">
                                                            {{ $item['name'] }}
                                                        </a>
                                                    </td>
                                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px;white-space:nowrap;">
                                                        @if ($is_credits)
                                                            {{ number_format($item['price']) }} credits
                                                        @else
                                                            ${{ number_format($item['price'], 2) }}
                                                        @endif
                                                    </td>
                                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px;white-space:nowrap;color:#6b7280;">
                                                        x{{ $item['quantity'] }}
                                                    </td>
                                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;text-align:right;border-top:1px dashed #e5e7eb;padding:8px 4px;white-space:nowrap;font-weight:600;">
                                                        @if ($is_credits)
                                                            {{ number_format($item['item_total']) }} credits
                                                        @else
                                                            ${{ number_format($item['item_total'], 2) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td style="padding:10px 4px 4px;border-top:1px dashed #e5e7eb;" colspan="2">&nbsp;</td>
                                                <th style="text-align:right;border-top:1px dashed #e5e7eb;padding:10px 4px 4px;font-weight:600;color:#374151;">
                                                    Total Due
                                                </th>
                                                <th style="text-align:right;border-top:1px dashed #e5e7eb;padding:10px 4px 4px;font-weight:700;color:#111827;">
                                                    @if ($is_credits)
                                                        {{ number_format($total_amount) }} credits
                                                    @else
                                                        ${{ number_format($total_amount, 2) }}
                                                    @endif
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                {{-- Notes --}}
                                @if (!empty($notes))
                                    <div style="margin:24px 0 0;padding:16px;background-color:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;">
                                            Notes
                                        </p>
                                        <p style="margin:0;font-size:14px;color:#374151;">
                                            {{ $notes }}
                                        </p>
                                    </div>
                                @endif

                                {{-- CTA --}}
                                <div style="box-sizing:border-box;text-align:center;margin:48px 0 0;">
                                    <a href="{{ $invoice_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 40px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        View Invoice
                                    </a>
                                </div>

                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <div style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#9ca3af;padding:20px;text-align:center;">
                        <p style="margin:0;font-size:12px;color:#9ca3af;">
                            &copy; {{ date('Y') }} BASE Search Marketing. All rights reserved.
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
