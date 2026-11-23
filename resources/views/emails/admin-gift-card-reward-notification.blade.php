<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>Gift card request | {{ config('app.name') }}</title>
    <style>
        /* Mobile first tweaks. Most admins read this on their phone. */
        @media only screen and (max-width: 600px) {
            .gc-wrapper { padding: 12px 8px 24px !important; }
            .gc-hero { padding: 28px 22px 24px !important; }
            .gc-hero-title { font-size: 24px !important; line-height: 30px !important; }
            .gc-body { padding: 0 18px 24px !important; }
            .gc-gift-card { padding: 20px !important; }
            .gc-gift-amount { font-size: 40px !important; line-height: 44px !important; }
            .gc-send-email { font-size: 16px !important; }
            .gc-stack { display: block !important; width: 100% !important; padding: 0 !important; }
            .gc-stack-gap { padding-bottom: 10px !important; }
            .gc-detail-label { display: block !important; width: 100% !important; padding-bottom: 2px !important; }
            .gc-detail-value { display: block !important; width: 100% !important; text-align: left !important; }
            .gc-button { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 16px 12px !important; font-size: 16px !important; }
            .gc-hide-mobile { display: none !important; }
        }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    </style>
</head>

<body style="margin:0;padding:0;background-color:#f9f0f5;font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#374151;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    @php
        $brand_color   = '#ec3c89';
        $brand_dark    = '#be185d';
        $brand_soft    = '#fce7f3';
        $festive_from  = '#ec3c89';
        $festive_to    = '#8b5cf6';
        $app_name      = config('app.name');
        $first_name    = trim((string) $recipient_name);

        $gift_card_themes = [
            'amazon' => ['background' => '#232f3e', 'shine' => '#37475a', 'accent' => '#ff9900', 'text' => '#ffffff', 'muted' => '#c9d1db'],
            'visa'   => ['background' => '#1a1f71', 'shine' => '#2b33a8', 'accent' => '#f7b600', 'text' => '#ffffff', 'muted' => '#c7cbf2'],
            'target' => ['background' => '#cc0000', 'shine' => '#e31b23', 'accent' => '#ffffff', 'text' => '#ffffff', 'muted' => '#ffd6d6'],
        ];
        $theme = $gift_card_themes[$gift_card_brand]
            ?? ['background' => '#111827', 'shine' => '#1f2937', 'accent' => $brand_color, 'text' => '#ffffff', 'muted' => '#d1d5db'];

        $details = array_values(array_filter([
            ['Gift card', '1 ' . $gift_card_label . ' gift card for the whole order'],
            ['Value', $reward_amount . ' (' . $reward_percentage . ' back' . ($reward_cap !== '' ? ', capped at the ' . $reward_cap . ' promo limit' : '') . ')'],
            ['Order total', $order_total],
            ['Products', $products],
            ['Order', '#' . $order_reference],
            ['Submitted', $submitted_at],
            $promotion_ends_at !== '' ? ['Promo ends', $promotion_ends_at] : null,
        ]));

        $steps = [
            ['Buy the gift card', 'Get a ' . $reward_amount . ' ' . $gift_card_label . ' gift card.'],
            ['Send it to the client', 'Deliver it to ' . $gift_card_email . '.'],
            ['Mark it as sent', 'Update the request in ' . $app_name . ' so the team knows it is done.'],
        ];
    @endphp

    {{-- Inbox preview text --}}
    <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#f9f0f5;">
        {{ $client_name }} used {{ $promo_code }}. Send a {{ $reward_amount }} {{ $gift_card_label }} gift card to {{ $gift_card_email }}.
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f9f0f5" style="background-color:#f9f0f5;">
        <tr>
            <td align="center" class="gc-wrapper" style="padding:28px 16px 36px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                    {{-- Logo --}}
                    <tr>
                        <td align="center" style="padding:0 0 18px;">
                            <a href="{{ $view_claims_url }}" target="_blank" style="text-decoration:none;">
                                <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                    alt="{{ $app_name }}" width="140"
                                    style="display:block;width:140px;max-width:140px;height:auto;border:0;outline:none;">
                            </a>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td bgcolor="#ffffff" style="background-color:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 24px rgba(190,24,93,0.08);">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

                                {{-- Festive hero --}}
                                <tr>
                                    <td class="gc-hero" bgcolor="{{ $festive_from }}" align="center"
                                        style="background-color:{{ $festive_from }};background-image:linear-gradient(135deg,{{ $festive_from }} 0%,{{ $festive_to }} 100%);padding:34px 32px 30px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                                            <tr>
                                                <td style="background-color:rgba(255,255,255,0.18);border-radius:999px;padding:6px 14px;font-size:11px;line-height:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#ffffff;">
                                                    &#127873;&nbsp; New gift card request
                                                </td>
                                            </tr>
                                        </table>
                                        <h1 class="gc-hero-title"
                                            style="margin:16px 0 8px;font-size:28px;line-height:34px;font-weight:800;color:#ffffff;letter-spacing:-0.3px;">
                                            Send a {{ $reward_amount }} {{ $gift_card_label }} gift card
                                        </h1>
                                        <p style="margin:0;font-size:15px;line-height:22px;color:#fde7f3;">
                                            {{ $client_name }} used
                                            <span style="font-family:Menlo,Consolas,'Courier New',monospace;font-weight:700;letter-spacing:1px;color:#ffffff;">{{ $promo_code }}</span>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="gc-body" style="padding:0 32px 32px;">

                                        {{-- Greeting --}}
                                        <p style="margin:26px 0 20px;font-size:15px;line-height:24px;color:#374151;">
                                            Hi{{ $first_name !== '' ? ' ' . $first_name : '' }}, a client just told us where to send their money back reward. Everything you need is below.
                                        </p>

                                        {{-- Gift card visual --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                            <tr>
                                                <td class="gc-gift-card" bgcolor="{{ $theme['background'] }}"
                                                    style="background-color:{{ $theme['background'] }};background-image:linear-gradient(135deg,{{ $theme['background'] }} 0%,{{ $theme['shine'] }} 100%);border-radius:16px;padding:24px 26px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="font-size:13px;line-height:16px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:{{ $theme['accent'] }};">
                                                                {{ $gift_card_label }}
                                                            </td>
                                                            <td align="right" style="font-size:11px;line-height:16px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:{{ $theme['muted'] }};">
                                                                Gift card
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:20px 0 4px;">
                                                                <span class="gc-gift-amount" style="font-size:44px;line-height:48px;font-weight:800;letter-spacing:-1px;color:{{ $theme['text'] }};">
                                                                    {{ $reward_amount }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:0 0 18px;font-size:13px;line-height:20px;color:{{ $theme['muted'] }};">
                                                                {{ $reward_percentage }} back on a {{ $order_total }} order{{ $reward_cap !== '' ? ', capped at ' . $reward_cap : '' }}
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="border-top:1px dashed {{ $theme['muted'] }};padding-top:14px;">
                                                                <p style="margin:0 0 4px;font-size:10px;line-height:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:{{ $theme['muted'] }};">
                                                                    Send to
                                                                </p>
                                                                <a href="mailto:{{ $gift_card_email }}" class="gc-send-email"
                                                                    style="font-size:18px;line-height:24px;font-weight:700;color:{{ $theme['text'] }};text-decoration:none;word-break:break-all;">
                                                                    {{ $gift_card_email }}
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Pay later warning --}}
                                        @if ($is_payment_pending)
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                                <tr>
                                                    <td bgcolor="#fffbeb" style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;font-size:14px;line-height:21px;color:#92400e;">
                                                        <strong style="color:#78350f;">&#9888;&#65039; Payment pending.</strong>
                                                        This order was placed with pay later and has not been paid yet. Confirm the payment before you send the gift card.
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif

                                        {{-- Primary action, kept high so it is visible without scrolling on a phone --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
                                            <tr>
                                                <td class="gc-stack gc-stack-gap" width="60%" style="padding-right:6px;">
                                                    <a href="{{ $view_claims_url }}" target="_blank" class="gc-button"
                                                        style="display:block;text-align:center;text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};border:1px solid {{ $brand_color }};padding:14px 16px;border-radius:12px;font-size:15px;line-height:20px;font-weight:700;">
                                                        Open gift card requests
                                                    </a>
                                                </td>
                                                <td class="gc-stack" width="40%" style="padding-left:6px;">
                                                    <a href="{{ $view_order_url }}" target="_blank" class="gc-button"
                                                        style="display:block;text-align:center;text-decoration:none;color:#374151;background-color:#ffffff;border:1px solid #e5e7eb;padding:14px 16px;border-radius:12px;font-size:15px;line-height:20px;font-weight:600;">
                                                        View order
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Next steps --}}
                                        <p style="margin:0 0 12px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">
                                            Next steps
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
                                            @foreach ($steps as $index => [$step_title, $step_text])
                                                <tr>
                                                    <td width="40" valign="top" style="padding:0 12px {{ $loop->last ? '0' : '14px' }} 0;">
                                                        <div style="width:28px;height:28px;border-radius:999px;background-color:{{ $brand_soft }};color:{{ $brand_dark }};font-size:13px;line-height:28px;font-weight:800;text-align:center;">
                                                            {{ $index + 1 }}
                                                        </div>
                                                    </td>
                                                    <td valign="top" style="padding:0 0 {{ $loop->last ? '0' : '14px' }};">
                                                        <p style="margin:3px 0 2px;font-size:15px;line-height:20px;font-weight:700;color:#111827;">{{ $step_title }}</p>
                                                        <p style="margin:0;font-size:14px;line-height:20px;color:#6b7280;word-break:break-word;">{{ $step_text }}</p>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>

                                        {{-- Client --}}
                                        <p style="margin:0 0 12px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">
                                            Client
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f9fafb"
                                            style="margin:0 0 26px;background-color:#f9fafb;border:1px solid #f0f0f3;border-radius:14px;">
                                            <tr>
                                                <td style="padding:16px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td width="56" valign="middle" style="padding-right:12px;">
                                                                <div style="width:44px;height:44px;border-radius:999px;background-color:{{ $brand_color }};background-image:linear-gradient(135deg,{{ $festive_from }},{{ $festive_to }});color:#ffffff;font-size:16px;line-height:44px;font-weight:700;text-align:center;">
                                                                    {{ $client_initials }}
                                                                </div>
                                                            </td>
                                                            <td valign="middle">
                                                                <p style="margin:0;font-size:15px;line-height:20px;font-weight:700;color:#111827;">{{ $client_name }}</p>
                                                                @if ($client_email)
                                                                    <p style="margin:2px 0 0;font-size:13px;line-height:18px;color:#6b7280;word-break:break-all;">{{ $client_email }}</p>
                                                                @endif
                                                                @if ($client_company)
                                                                    <p style="margin:2px 0 0;font-size:13px;line-height:18px;color:#9ca3af;">{{ $client_company }}</p>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Details --}}
                                        <p style="margin:0 0 12px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">
                                            Request details
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 8px;border:1px solid #f0f0f3;border-radius:14px;">
                                            @foreach ($details as [$label, $value])
                                                <tr>
                                                    <td style="padding:12px 16px;{{ $loop->last ? '' : 'border-bottom:1px solid #f3f4f6;' }}">
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td class="gc-detail-label" width="40%" valign="top"
                                                                    style="font-size:13px;line-height:20px;color:#6b7280;">
                                                                    {{ $label }}
                                                                </td>
                                                                <td class="gc-detail-value" align="right" valign="top"
                                                                    style="font-size:14px;line-height:20px;font-weight:600;color:#111827;text-align:right;word-break:break-word;">
                                                                    {{ $value }}
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:22px 12px 0;">
                            <p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#9ca3af;">
                                You received this email because you are a recipient in
                                <a href="{{ $settings_url }}" target="_blank" style="color:{{ $brand_color }};text-decoration:none;font-weight:600;">Email Notification Settings</a>.
                            </p>
                            <p style="margin:0 0 4px;font-size:12px;line-height:18px;color:#9ca3af;">
                                &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                            </p>
                            <p style="margin:0;font-size:11px;line-height:16px;color:#d1d5db;">
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
