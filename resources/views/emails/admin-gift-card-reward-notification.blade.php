<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <title>Gift Card Request | {{ config('app.name') }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .gc-wrapper { padding: 12px !important; }
            .gc-card-body { padding: 0 20px 28px !important; }
            .gc-hero-title { font-size: 20px !important; }
            .gc-gift-card { padding: 20px !important; }
            .gc-gift-amount { font-size: 34px !important; }
            .gc-stack { display: block !important; width: 100% !important; padding: 0 !important; }
            .gc-stack-gap { padding-bottom: 12px !important; }
            .gc-label-cell { display: block !important; width: 100% !important; padding-bottom: 2px !important; }
            .gc-value-cell { display: block !important; width: 100% !important; }
            .gc-button { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .gc-send-email { font-size: 15px !important; }
        }
    </style>
</head>

<body style="margin:0;padding:0;background-color:#f9f0f5;font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#374151;-webkit-text-size-adjust:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_bg    = '#fce7f3';
        $app_name    = config('app.name');

        $gift_card_themes = [
            'amazon' => ['background' => '#232f3e', 'accent' => '#ff9900', 'text' => '#ffffff', 'muted' => '#c9d1db'],
            'visa'   => ['background' => '#1a1f71', 'accent' => '#f7b600', 'text' => '#ffffff', 'muted' => '#c7cbf2'],
            'target' => ['background' => '#cc0000', 'accent' => '#ffffff', 'text' => '#ffffff', 'muted' => '#ffd6d6'],
        ];
        $theme = $gift_card_themes[$gift_card_brand] ?? ['background' => '#111827', 'accent' => $brand_color, 'text' => '#ffffff', 'muted' => '#d1d5db'];
    @endphp

    {{-- Preheader (shown in the inbox preview) --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        {{ $client_name }} would like a {{ $reward_amount }} {{ $gift_card_label }} gift card sent to {{ $gift_card_email }}.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9f0f5;">
        <tr>
            <td align="center" class="gc-wrapper" style="padding:24px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">

                    {{-- Logo --}}
                    <tr>
                        <td align="center" style="padding:0 20px 20px;">
                            <a href="{{ $view_order_url }}" target="_blank" style="text-decoration:none;">
                                <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                    alt="{{ $app_name }}" style="max-width:200px;max-height:50px;border:0;">
                            </a>
                        </td>
                    </tr>

                    {{-- Main card --}}
                    <tr>
                        <td style="background-color:#ffffff;border-radius:12px;overflow:hidden;border-top:4px solid {{ $brand_color }};">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="gc-card-body" style="padding:0 40px 36px;">

                                        {{-- Badge --}}
                                        <div style="text-align:center;padding:28px 0 14px;">
                                            <span style="display:inline-block;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                                &#127873; GIFT CARD REQUEST
                                            </span>
                                        </div>

                                        {{-- Heading --}}
                                        <h1 class="gc-hero-title" align="center"
                                            style="margin:0 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#111827;">
                                            A client is ready for their gift card
                                        </h1>
                                        <p align="center" style="margin:0 0 26px;color:#6b7280;font-size:13px;">
                                            {{ $promotion_name }} &middot; code <strong style="font-family:Menlo,Consolas,monospace;color:#111827;letter-spacing:1px;">{{ $promo_code }}</strong>
                                        </p>

                                        {{-- Greeting --}}
                                        <p style="margin:0 0 14px;font-size:15px;color:#374151;">
                                            @if ($recipient_name)
                                                Hello <strong>{{ $recipient_name }}</strong>,
                                            @else
                                                Hello,
                                            @endif
                                        </p>
                                        <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">
                                            <strong>{{ $client_name }}</strong> checked out with the <strong>{{ $promo_code }}</strong> promo code and just told us where to send their money back reward. Here is everything you need to send it.
                                        </p>

                                        {{-- Gift card visual --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                                            <tr>
                                                <td class="gc-gift-card" bgcolor="{{ $theme['background'] }}"
                                                    style="background-color:{{ $theme['background'] }};border-radius:14px;padding:24px 26px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:{{ $theme['accent'] }};">
                                                                {{ $gift_card_label }}
                                                            </td>
                                                            <td align="right" style="font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:{{ $theme['muted'] }};">
                                                                Gift Card
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:18px 0 4px;">
                                                                <span class="gc-gift-amount" style="font-size:40px;line-height:1;font-weight:800;color:{{ $theme['text'] }};letter-spacing:-0.5px;">
                                                                    {{ $reward_amount }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="font-size:13px;color:{{ $theme['muted'] }};padding-bottom:18px;">
                                                                {{ $reward_percentage }} back on a {{ $order_total }} order
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="border-top:1px dashed {{ $theme['muted'] }};padding-top:14px;">
                                                                <p style="margin:0 0 2px;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:{{ $theme['muted'] }};">
                                                                    Send to
                                                                </p>
                                                                <a href="mailto:{{ $gift_card_email }}" class="gc-send-email"
                                                                    style="font-size:17px;font-weight:700;color:{{ $theme['text'] }};text-decoration:none;word-break:break-all;">
                                                                    {{ $gift_card_email }}
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Payment pending warning --}}
                                        @if ($is_payment_pending)
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                                                <tr>
                                                    <td style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;font-size:13px;line-height:1.5;color:#92400e;">
                                                        <strong>Heads up:</strong> this order was placed with the pay later option and is still awaiting payment. You may want to confirm payment before sending the gift card.
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif

                                        {{-- Client info --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 20px;background-color:#f9fafb;border-radius:8px;">
                                            <tr>
                                                <td style="padding:16px 20px;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                        <tr>
                                                            <td width="54" style="vertical-align:middle;padding-right:14px;">
                                                                <div style="width:40px;height:40px;border-radius:50%;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:15px;font-weight:700;text-align:center;line-height:40px;">
                                                                    {{ $client_initials }}
                                                                </div>
                                                            </td>
                                                            <td style="vertical-align:middle;">
                                                                <p style="margin:0 0 2px;font-size:14px;font-weight:600;color:#111827;">
                                                                    {{ $client_name }}
                                                                </p>
                                                                @if ($client_email)
                                                                    <p style="margin:0;font-size:12px;color:#6b7280;word-break:break-all;">
                                                                        {{ $client_email }}
                                                                    </p>
                                                                @endif
                                                                @if ($client_company)
                                                                    <p style="margin:2px 0 0;font-size:12px;color:#9ca3af;">
                                                                        {{ $client_company }}
                                                                    </p>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Request details --}}
                                        <p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">
                                            Request details
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 26px;background-color:#f9fafb;border-radius:8px;">
                                            @foreach ([
                                                ['Gift card', $gift_card_label],
                                                ['Gift card value', $reward_amount . ' (' . $reward_percentage . ' back)'],
                                                ['Send to', $gift_card_email],
                                                ['Order total', $order_total],
                                                ['Products', $products],
                                                ['Order reference', '#' . $order_reference],
                                                ['Submitted', $submitted_at],
                                            ] as $index => [$label, $value])
                                                <tr>
                                                    <td style="padding:12px 20px;{{ $index < 6 ? 'border-bottom:1px solid #f0f0f3;' : '' }}">
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td class="gc-label-cell" width="140" style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;vertical-align:top;">
                                                                    {{ $label }}
                                                                </td>
                                                                <td class="gc-value-cell" style="font-size:14px;color:#111827;font-weight:500;word-break:break-word;">
                                                                    {{ $value }}
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>

                                        {{-- Actions --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                                            <tr>
                                                <td class="gc-stack gc-stack-gap" width="50%" style="padding-right:6px;">
                                                    <a href="{{ $view_claims_url }}" target="_blank" class="gc-button"
                                                        style="display:block;text-align:center;text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 16px;border-radius:8px;font-size:14px;font-weight:600;">
                                                        Review Gift Card Requests
                                                    </a>
                                                </td>
                                                <td class="gc-stack" width="50%" style="padding-left:6px;">
                                                    <a href="{{ $view_order_url }}" target="_blank" class="gc-button"
                                                        style="display:block;text-align:center;text-decoration:none;color:#374151;background-color:#ffffff;border:1px solid #e5e7eb;padding:11px 16px;border-radius:8px;font-size:14px;font-weight:600;">
                                                        View Order
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;text-align:center;">
                                            Once the gift card has been sent, mark the request as sent in the portal so the team knows it is done.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:20px;color:#9ca3af;">
                            <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;line-height:1.6;">
                                You received this email because you are on the admin notification list.
                                <a href="{{ $settings_url }}" target="_blank" style="color:{{ $brand_color }};text-decoration:none;">Manage notification settings</a>
                            </p>
                            <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;">
                                &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                            </p>
                            <p style="margin:0;font-size:11px;color:#d1d5db;">
                                Sent to {{ $recipient_email }}
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>

</html>
