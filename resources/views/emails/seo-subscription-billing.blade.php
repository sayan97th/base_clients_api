<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>SEO subscription | {{ config('app.name') }}</title>
    <style>
        /* Mobile first. Most clients open this on an iPhone or a Galaxy. */
        @media only screen and (max-width: 600px) {
            .ss-wrapper { padding: 12px 8px 24px !important; }
            .ss-hero { padding: 30px 22px 26px !important; }
            .ss-hero-title { font-size: 24px !important; line-height: 30px !important; }
            .ss-body { padding: 0 18px 26px !important; }
            .ss-ticket { padding: 20px !important; }
            .ss-amount { font-size: 40px !important; line-height: 44px !important; }
            .ss-stack { display: block !important; width: 100% !important; padding: 0 !important; }
            .ss-stack-gap { padding-bottom: 10px !important; }
            .ss-detail-label { display: block !important; width: 100% !important; padding-bottom: 2px !important; }
            .ss-detail-value { display: block !important; width: 100% !important; text-align: left !important; }
            .ss-button { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 16px 12px !important; font-size: 16px !important; }
        }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    </style>
</head>

<body style="margin:0;padding:0;background-color:#f9f0f5;font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#374151;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    @php
        $brand_color = '#ec3c89';
        $brand_dark  = '#be185d';
        $brand_soft  = '#fce7f3';
        $app_name    = config('app.name');
        $first_name  = trim((string) $client_first_name);

        $themes = [
            'payment_succeeded' => [
                'from'       => '#ec3c89',
                'to'         => '#8b5cf6',
                'chip'       => '&#10003;&nbsp; Payment received',
                'title'      => 'Your SEO subscription is paid',
                'subtitle'   => $plan_name . ' for ' . $period_label,
                'preview'    => 'We charged ' . $amount . ' for your ' . $plan_name . ' subscription (' . $period_label . '). Thank you!',
                'intro'      => 'thank you! This is a confirmation that your monthly payment for your SEO subscription with ' . $app_name . ' went through. No action is needed on your side.',
                'ticket_tag' => 'Paid',
                'tag_bg'     => '#dcfce7',
                'tag_text'   => '#166534',
            ],
            'payment_failed' => [
                'from'       => '#f97316',
                'to'         => '#ec3c89',
                'chip'       => '&#9888;&#65039;&nbsp; Action needed',
                'title'      => 'We could not process your payment',
                'subtitle'   => $plan_name . ' for ' . $period_label,
                'preview'    => 'Your ' . $amount . ' SEO subscription payment for ' . $period_label . ' did not go through. It only takes a minute to fix.',
                'intro'      => 'we tried to charge your card on file for this month\'s SEO subscription, but the payment did not go through. Your SEO work is not affected right now, it only takes a minute to fix.',
                'ticket_tag' => 'Payment due',
                'tag_bg'     => '#ffedd5',
                'tag_text'   => '#9a3412',
            ],
            'renewal_reminder' => [
                'from'       => '#ec3c89',
                'to'         => '#8b5cf6',
                'chip'       => '&#128197;&nbsp; Upcoming renewal',
                'title'      => 'Your SEO subscription renews on ' . $billing_date,
                'subtitle'   => 'Just a friendly heads up, nothing to do',
                'preview'    => 'On ' . $billing_date . ' we will charge ' . $amount . ' to your card on file for your ' . $plan_name . ' subscription.',
                'intro'      => 'this is a friendly reminder that your SEO subscription renews soon. We will automatically charge your card on file, so you do not need to do anything.',
                'ticket_tag' => 'Scheduled',
                'tag_bg'     => $brand_soft,
                'tag_text'   => $brand_dark,
            ],
        ];

        $theme = $themes[$variant] ?? $themes['renewal_reminder'];

        $card_text = $card_label !== '' ? $card_label : 'Card on file';

        $amount_caption = match ($variant) {
            'payment_succeeded' => 'Charged to ' . $card_text,
            'payment_failed'    => 'Amount due for ' . $period_label,
            default             => 'Will be charged to ' . $card_text,
        };

        $details = array_values(array_filter([
            ['Subscription', $plan_name],
            ['Billing period', $period_range],
            $variant === 'renewal_reminder' ? ['Renewal date', $billing_date] : null,
            $variant === 'payment_succeeded' && $paid_at !== '' ? ['Paid on', $paid_at] : null,
            ['Payment method', $card_text],
            $invoice_number !== '' ? ['Invoice', '#' . $invoice_number] : null,
            $variant === 'payment_succeeded' && $next_billing_date !== '' ? ['Next payment', $next_billing_date] : null,
            ['Billing', 'Monthly, renews automatically'],
        ]));

        if ($variant === 'payment_failed') {
            $steps = [
                ['Check your card', $failure_reason !== '' ? $failure_reason . ' Make sure your card details are up to date.' : 'Make sure your card details are up to date.'],
                ['Pay the invoice', 'Pay invoice #' . $invoice_number . ' securely from your portal with any card.'],
                ['You are all set', $next_retry_date !== '' ? 'If you update your card, we will try again automatically on ' . $next_retry_date . '.' : 'Once the invoice is paid, your subscription continues as usual.'],
            ];
        } elseif ($variant === 'renewal_reminder') {
            $steps = [
                ['We charge your card', 'On ' . $billing_date . ' we charge ' . $amount . ' to ' . $card_text . '.'],
                ['You get a receipt', 'We email you a confirmation with your invoice right after.'],
                ['Your SEO work continues', 'Your ' . $plan_name . ' services keep running without interruption.'],
            ];
        } else {
            $steps = [];
        }

        $primary_action = match ($variant) {
            'payment_failed'    => ['Pay invoice now', $invoice_url !== '' ? $invoice_url : $billing_url],
            'payment_succeeded' => ['View invoice', $invoice_url !== '' ? $invoice_url : $billing_url],
            default             => ['Review payment method', $billing_url],
        };

        $secondary_action = match ($variant) {
            'payment_failed' => ['Update card', $billing_url],
            default          => null,
        };
    @endphp

    {{-- Inbox preview text --}}
    <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#f9f0f5;">
        {{ $theme['preview'] }}
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f9f0f5" style="background-color:#f9f0f5;">
        <tr>
            <td align="center" class="ss-wrapper" style="padding:28px 16px 36px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                    {{-- Logo --}}
                    <tr>
                        <td align="center" style="padding:0 0 18px;">
                            <a href="{{ $billing_url }}" target="_blank" style="text-decoration:none;">
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

                                {{-- Hero --}}
                                <tr>
                                    <td class="ss-hero" bgcolor="{{ $theme['from'] }}" align="center"
                                        style="background-color:{{ $theme['from'] }};background-image:linear-gradient(135deg,{{ $theme['from'] }} 0%,{{ $theme['to'] }} 100%);padding:34px 32px 30px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                                            <tr>
                                                <td style="background-color:rgba(255,255,255,0.18);border-radius:999px;padding:6px 14px;font-size:11px;line-height:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#ffffff;">
                                                    {!! $theme['chip'] !!}
                                                </td>
                                            </tr>
                                        </table>
                                        <h1 class="ss-hero-title"
                                            style="margin:16px 0 8px;font-size:28px;line-height:34px;font-weight:800;color:#ffffff;letter-spacing:-0.3px;">
                                            {{ $theme['title'] }}
                                        </h1>
                                        <p style="margin:0;font-size:15px;line-height:22px;color:#fde7f3;">
                                            {{ $theme['subtitle'] }}
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="ss-body" style="padding:0 32px 32px;">

                                        {{-- Greeting --}}
                                        <p style="margin:26px 0 20px;font-size:15px;line-height:24px;color:#374151;">
                                            Hi{{ $first_name !== '' ? ' ' . $first_name : '' }}, {{ $theme['intro'] }}
                                        </p>

                                        {{-- Receipt ticket --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                            <tr>
                                                <td class="ss-ticket" bgcolor="#111827"
                                                    style="background-color:#111827;background-image:linear-gradient(135deg,#111827 0%,#1f2937 100%);border-radius:16px;padding:24px 26px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="font-size:12px;line-height:16px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:{{ $brand_color }};">
                                                                SEO subscription
                                                            </td>
                                                            <td align="right">
                                                                <span style="display:inline-block;background-color:{{ $theme['tag_bg'] }};color:{{ $theme['tag_text'] }};border-radius:999px;padding:4px 10px;font-size:11px;line-height:14px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;">
                                                                    {{ $theme['ticket_tag'] }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:20px 0 2px;">
                                                                <span class="ss-amount" style="font-size:44px;line-height:48px;font-weight:800;letter-spacing:-1px;color:#ffffff;">
                                                                    {{ $amount }}
                                                                </span>
                                                                <span style="font-size:15px;line-height:20px;font-weight:600;color:#9ca3af;">&nbsp;/ month</span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:0 0 18px;font-size:13px;line-height:20px;color:#d1d5db;">
                                                                {{ $amount_caption }}
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="border-top:1px dashed #4b5563;padding-top:14px;">
                                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                                    <tr>
                                                                        <td valign="top">
                                                                            <p style="margin:0 0 4px;font-size:10px;line-height:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#9ca3af;">
                                                                                Plan
                                                                            </p>
                                                                            <p style="margin:0;font-size:16px;line-height:22px;font-weight:700;color:#ffffff;">
                                                                                {{ $plan_name }}
                                                                            </p>
                                                                        </td>
                                                                        <td valign="top" align="right">
                                                                            <p style="margin:0 0 4px;font-size:10px;line-height:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#9ca3af;">
                                                                                Period
                                                                            </p>
                                                                            <p style="margin:0;font-size:16px;line-height:22px;font-weight:700;color:#ffffff;">
                                                                                {{ $period_label }}
                                                                            </p>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Why am I getting this email --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                            <tr>
                                                <td bgcolor="{{ $variant === 'payment_failed' ? '#fff7ed' : '#fdf2f8' }}"
                                                    style="background-color:{{ $variant === 'payment_failed' ? '#fff7ed' : '#fdf2f8' }};border:1px solid {{ $variant === 'payment_failed' ? '#fed7aa' : '#fbcfe8' }};border-radius:12px;padding:14px 16px;font-size:14px;line-height:21px;color:{{ $variant === 'payment_failed' ? '#9a3412' : '#831843' }};">
                                                    @if ($variant === 'payment_failed')
                                                        <strong style="color:#7c2d12;">Why this happened.</strong>
                                                        {{ $failure_reason !== '' ? $failure_reason : 'Your bank declined the payment.' }}
                                                        This charge is for your monthly {{ $plan_name }} subscription for {{ $period_label }}.
                                                    @else
                                                        <strong style="color:#831843;">What is this charge?</strong>
                                                        This is your monthly payment for your {{ $plan_name }} subscription with {{ $app_name }}, covering {{ $period_range }}. It renews automatically every month.
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Actions, kept high so they are visible without scrolling on a phone --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
                                            <tr>
                                                <td class="ss-stack {{ $secondary_action ? 'ss-stack-gap' : '' }}" width="{{ $secondary_action ? '60%' : '100%' }}" style="{{ $secondary_action ? 'padding-right:6px;' : '' }}">
                                                    <a href="{{ $primary_action[1] }}" target="_blank" class="ss-button"
                                                        style="display:block;text-align:center;text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};border:1px solid {{ $brand_color }};padding:14px 16px;border-radius:12px;font-size:15px;line-height:20px;font-weight:700;">
                                                        {{ $primary_action[0] }}
                                                    </a>
                                                </td>
                                                @if ($secondary_action)
                                                    <td class="ss-stack" width="40%" style="padding-left:6px;">
                                                        <a href="{{ $secondary_action[1] }}" target="_blank" class="ss-button"
                                                            style="display:block;text-align:center;text-decoration:none;color:#374151;background-color:#ffffff;border:1px solid #e5e7eb;padding:14px 16px;border-radius:12px;font-size:15px;line-height:20px;font-weight:600;">
                                                            {{ $secondary_action[0] }}
                                                        </a>
                                                    </td>
                                                @endif
                                            </tr>
                                        </table>

                                        {{-- What happens next --}}
                                        @if (count($steps) > 0)
                                            <p style="margin:0 0 12px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">
                                                {{ $variant === 'payment_failed' ? 'How to fix it' : 'What happens next' }}
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
                                        @endif

                                        {{-- Details --}}
                                        <p style="margin:0 0 12px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">
                                            {{ $variant === 'payment_succeeded' ? 'Receipt details' : 'Subscription details' }}
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 22px;border:1px solid #f0f0f3;border-radius:14px;">
                                            @foreach ($details as [$label, $value])
                                                <tr>
                                                    <td style="padding:12px 16px;{{ $loop->last ? '' : 'border-bottom:1px solid #f3f4f6;' }}">
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td class="ss-detail-label" width="40%" valign="top"
                                                                    style="font-size:13px;line-height:20px;color:#6b7280;">
                                                                    {{ $label }}
                                                                </td>
                                                                <td class="ss-detail-value" align="right" valign="top"
                                                                    style="font-size:14px;line-height:20px;font-weight:600;color:#111827;text-align:right;word-break:break-word;">
                                                                    {{ $value }}
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            <tr>
                                                <td bgcolor="#f9fafb" style="background-color:#f9fafb;padding:14px 16px;border-top:1px solid #f3f4f6;border-radius:0 0 14px 14px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="font-size:14px;line-height:20px;font-weight:700;color:#111827;">
                                                                {{ $variant === 'payment_succeeded' ? 'Total paid' : ($variant === 'payment_failed' ? 'Amount due' : 'Amount to be charged') }}
                                                            </td>
                                                            <td align="right" style="font-size:18px;line-height:22px;font-weight:800;color:{{ $brand_dark }};">
                                                                {{ $amount }}
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Help --}}
                                        <p style="margin:0;font-size:14px;line-height:22px;color:#6b7280;">
                                            @if ($variant === 'renewal_reminder')
                                                Want to change your card or have a question about your plan?
                                            @else
                                                Questions about this payment?
                                            @endif
                                            <a href="{{ $support_url }}" target="_blank" style="color:{{ $brand_color }};text-decoration:none;font-weight:700;">Contact our team</a>, we are happy to help.
                                        </p>

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:22px 12px 0;">
                            <p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#9ca3af;">
                                You received this email because you have an active SEO subscription with {{ $app_name }}.
                            </p>
                            <p style="margin:0 0 4px;font-size:12px;line-height:18px;color:#9ca3af;">
                                &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                            </p>
                            <p style="margin:0;font-size:11px;line-height:16px;color:#d1d5db;">
                                Sent to {{ $client_email }}
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>

</html>
