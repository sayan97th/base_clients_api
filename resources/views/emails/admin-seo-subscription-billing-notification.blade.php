<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>SEO subscription payment | {{ config('app.name') }}</title>
    <style>
        /* Mobile first tweaks. Most admins read this on their phone. */
        @media only screen and (max-width: 600px) {
            .as-wrapper { padding: 12px 8px 24px !important; }
            .as-hero { padding: 28px 22px 24px !important; }
            .as-hero-title { font-size: 24px !important; line-height: 30px !important; }
            .as-body { padding: 0 18px 24px !important; }
            .as-amount-card { padding: 20px !important; }
            .as-amount { font-size: 40px !important; line-height: 44px !important; }
            .as-stack { display: block !important; width: 100% !important; padding: 0 !important; }
            .as-stack-gap { padding-bottom: 10px !important; }
            .as-detail-label { display: block !important; width: 100% !important; padding-bottom: 2px !important; }
            .as-detail-value { display: block !important; width: 100% !important; text-align: left !important; }
            .as-button { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 16px 12px !important; font-size: 16px !important; }
        }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    </style>
</head>

<body style="margin:0;padding:0;background-color:#f9f0f5;font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#374151;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    @php
        $brand_color  = '#ec3c89';
        $brand_dark   = '#be185d';
        $brand_soft   = '#fce7f3';
        $app_name     = config('app.name');
        $first_name   = trim((string) $recipient_name);
        $is_succeeded    = $variant === 'payment_succeeded';
        $is_card_removed = $variant === 'payment_method_removed';
        $is_failed       = ! $is_succeeded && ! $is_card_removed;

        $themes = [
            'payment_succeeded' => [
                'from'     => '#ec3c89',
                'to'       => '#8b5cf6',
                'chip'     => '&#10003;&nbsp; Subscription payment received',
                'title'    => 'Payment collected from ' . $client_name,
                'subtitle' => $plan_name . ' for ' . $period_label,
                'preview'  => $client_name . ' was charged ' . $amount . ' for the ' . $period_label . ' SEO subscription period.',
                'intro'    => 'an SEO subscription payment just went through. Here is everything about it.',
                'eyebrow'  => 'Collected',
                'tag'      => 'Paid',
                'tag_bg'   => '#dcfce7',
                'tag_text' => '#166534',
                'reason'   => '',
            ],
            'payment_method_removed' => [
                'from'     => '#f59e0b',
                'to'       => '#ec3c89',
                'chip'     => '&#128179;&nbsp; Card removed',
                'title'    => $client_name . ' has no card on file',
                'subtitle' => $plan_name . ' renews on ' . $next_billing_date,
                'preview'  => $client_name . ' removed their last card. The ' . $amount . ' SEO renewal on ' . $next_billing_date . ' will fail unless a new card is added.',
                'intro'    => 'a client with an auto renewing SEO subscription just removed their last saved card. We emailed them a link to add a new one.',
                'eyebrow'  => 'Next renewal',
                'tag'      => 'Card needed',
                'tag_bg'   => '#fef3c7',
                'tag_text' => '#92400e',
                'reason'   => 'What this means.',
            ],
            'payment_failed' => [
                'from'     => '#f97316',
                'to'       => '#ec3c89',
                'chip'     => '&#9888;&#65039;&nbsp; Subscription payment failed',
                'title'    => 'Payment failed for ' . $client_name,
                'subtitle' => $plan_name . ' for ' . $period_label,
                'preview'  => 'The ' . $amount . ' SEO subscription payment for ' . $client_name . ' (' . $period_label . ') failed. ' . ($next_retry_date !== '' ? 'Next retry on ' . $next_retry_date . '.' : 'No more automatic retries.'),
                'intro'    => 'an SEO subscription payment could not be collected. The client was emailed a link to pay the invoice and update their card.',
                'eyebrow'  => 'Not collected',
                'tag'      => $next_retry_date !== '' ? 'Retry scheduled' : 'Action needed',
                'tag_bg'   => '#ffedd5',
                'tag_text' => '#9a3412',
                'reason'   => 'Decline reason.',
            ],
        ];

        $theme = $themes[$variant] ?? $themes['payment_failed'];

        $details = array_values(array_filter([
            ['Plan', $plan_name . ($is_custom_price ? ' (custom price)' : '')],
            ['Billing period', $period_range],
            ['Card', $card_label],
            ['How', $trigger_label],
            $is_failed && $attempt_label !== '' ? ['Attempt', $attempt_label] : null,
            $invoice_number !== '' ? ['Invoice', '#' . $invoice_number] : null,
            ! $is_failed && $next_billing_date !== '' ? ['Next charge', $next_billing_date . ($auto_renew ? ' (auto renew)' : ' (manual)')] : null,
            $outstanding_label !== '' ? ['Outstanding', $outstanding_label] : null,
            [$is_succeeded ? 'Paid on' : ($is_card_removed ? 'Removed on' : 'Attempted on'), $occurred_at],
        ]));

        if ($is_card_removed) {
            $steps = [
                ['We let the client know', 'The client was emailed a link to add a new card from their billing page.'],
                ['Follow up if needed', 'If no card is added before ' . $next_billing_date . ', the renewal fails and you will get a payment failed email.'],
                ['Cancel if they are leaving', 'If the client is ending the service, cancel the subscription from its page at any time.'],
            ];
        } elseif ($is_failed) {
            $steps = [
                ['Check the reason', $failure_reason !== '' ? $failure_reason : 'The card was declined.'],
                $next_retry_date !== ''
                    ? ['Automatic retry', 'We will try the card again on ' . $next_retry_date . '. If the client adds a new card, we retry right away.']
                    : ['Follow up with the client', 'Automatic retries have stopped. Ask the client to update their card or pay the invoice from their portal.'],
                ['Charge or cancel', 'Use Charge now once the client has a valid card, or cancel the subscription if the client is not going to pay.'],
            ];
        } else {
            $steps = [];
        }
    @endphp

    {{-- Inbox preview text --}}
    <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#f9f0f5;">
        {{ $theme['preview'] }}
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f9f0f5" style="background-color:#f9f0f5;">
        <tr>
            <td align="center" class="as-wrapper" style="padding:28px 16px 36px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                    {{-- Logo --}}
                    <tr>
                        <td align="center" style="padding:0 0 18px;">
                            <a href="{{ $subscription_url }}" target="_blank" style="text-decoration:none;">
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
                                    <td class="as-hero" bgcolor="{{ $theme['from'] }}" align="center"
                                        style="background-color:{{ $theme['from'] }};background-image:linear-gradient(135deg,{{ $theme['from'] }} 0%,{{ $theme['to'] }} 100%);padding:34px 32px 30px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                                            <tr>
                                                <td style="background-color:rgba(255,255,255,0.18);border-radius:999px;padding:6px 14px;font-size:11px;line-height:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#ffffff;">
                                                    {!! $theme['chip'] !!}
                                                </td>
                                            </tr>
                                        </table>
                                        <h1 class="as-hero-title"
                                            style="margin:16px 0 8px;font-size:28px;line-height:34px;font-weight:800;color:#ffffff;letter-spacing:-0.3px;">
                                            {{ $theme['title'] }}
                                        </h1>
                                        <p style="margin:0;font-size:15px;line-height:22px;color:#fde7f3;">
                                            {{ $theme['subtitle'] }}
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="as-body" style="padding:0 32px 32px;">

                                        <p style="margin:26px 0 20px;font-size:15px;line-height:24px;color:#374151;">
                                            Hi{{ $first_name !== '' ? ' ' . $first_name : '' }}, {{ $theme['intro'] }}
                                        </p>

                                        {{-- Amount card --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                            <tr>
                                                <td class="as-amount-card" bgcolor="#111827"
                                                    style="background-color:#111827;background-image:linear-gradient(135deg,#111827 0%,#1f2937 100%);border-radius:16px;padding:24px 26px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="font-size:12px;line-height:16px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:{{ $brand_color }};">
                                                                {{ $theme['eyebrow'] }}
                                                            </td>
                                                            <td align="right">
                                                                <span style="display:inline-block;background-color:{{ $theme['tag_bg'] }};color:{{ $theme['tag_text'] }};border-radius:999px;padding:4px 10px;font-size:11px;line-height:14px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;">
                                                                    {{ $theme['tag'] }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:20px 0 2px;">
                                                                <span class="as-amount" style="font-size:44px;line-height:48px;font-weight:800;letter-spacing:-1px;color:#ffffff;">{{ $amount }}</span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding:0;font-size:13px;line-height:20px;color:#d1d5db;">
                                                                {{ $period_label }} &middot; {{ $card_label }}
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        @unless ($is_succeeded)
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                                <tr>
                                                    <td bgcolor="#fff7ed" style="background-color:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px 16px;font-size:14px;line-height:21px;color:#9a3412;">
                                                        <strong style="color:#7c2d12;">{{ $theme['reason'] }}</strong> {{ $failure_reason }}
                                                        @if ($outstanding_label !== '')
                                                            <br><strong style="color:#7c2d12;">Balance.</strong> {{ $outstanding_label }}.
                                                        @endif
                                                    </td>
                                                </tr>
                                            </table>
                                        @endunless

                                        {{-- Actions --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
                                            <tr>
                                                <td class="as-stack {{ $invoice_url !== '' ? 'as-stack-gap' : '' }}" width="{{ $invoice_url !== '' ? '60%' : '100%' }}" style="{{ $invoice_url !== '' ? 'padding-right:6px;' : '' }}">
                                                    <a href="{{ $subscription_url }}" target="_blank" class="as-button"
                                                        style="display:block;text-align:center;text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};border:1px solid {{ $brand_color }};padding:14px 16px;border-radius:12px;font-size:15px;line-height:20px;font-weight:700;">
                                                        Open subscription
                                                    </a>
                                                </td>
                                                @if ($invoice_url !== '')
                                                    <td class="as-stack" width="40%" style="padding-left:6px;">
                                                        <a href="{{ $invoice_url }}" target="_blank" class="as-button"
                                                            style="display:block;text-align:center;text-decoration:none;color:#374151;background-color:#ffffff;border:1px solid #e5e7eb;padding:14px 16px;border-radius:12px;font-size:15px;line-height:20px;font-weight:600;">
                                                            View invoice
                                                        </a>
                                                    </td>
                                                @endif
                                            </tr>
                                        </table>

                                        {{-- Next steps (failed and card removed) --}}
                                        @if (count($steps) > 0)
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
                                        @endif

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
                                                                <div style="width:44px;height:44px;border-radius:999px;background-color:{{ $brand_color }};background-image:linear-gradient(135deg,{{ $theme['from'] }},{{ $theme['to'] }});color:#ffffff;font-size:16px;line-height:44px;font-weight:700;text-align:center;">
                                                                    {{ $client_initials }}
                                                                </div>
                                                            </td>
                                                            <td valign="middle">
                                                                <p style="margin:0;font-size:15px;line-height:20px;font-weight:700;color:#111827;">{{ $client_name }}</p>
                                                                @if ($client_company !== '')
                                                                    <p style="margin:2px 0 0;font-size:13px;line-height:18px;font-weight:600;color:#4b5563;">{{ $client_company }}</p>
                                                                @endif
                                                                <p style="margin:2px 0 0;font-size:13px;line-height:18px;color:#6b7280;word-break:break-all;">{{ $client_email }}</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        {{-- Details --}}
                                        <p style="margin:0 0 12px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">
                                            Payment details
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                            style="margin:0 0 8px;border:1px solid #f0f0f3;border-radius:14px;">
                                            @foreach ($details as [$label, $value])
                                                <tr>
                                                    <td style="padding:12px 16px;{{ $loop->last ? '' : 'border-bottom:1px solid #f3f4f6;' }}">
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td class="as-detail-label" width="40%" valign="top" style="font-size:13px;line-height:20px;color:#6b7280;">
                                                                    {{ $label }}
                                                                </td>
                                                                <td class="as-detail-value" align="right" valign="top"
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
