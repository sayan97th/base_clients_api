<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to the New {{ $platform_name }}</title>
</head>

<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#0f172a;width:100%;">

    @php
        $brand_color     = '#ec3c89';
        $brand_secondary = '#6366f1';
        $accent_teal     = '#14b8a6';
    @endphp

    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:0;box-sizing:border-box;width:100%;background-color:#0f172a;">
        <tr>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
            <td width="600"
                style="box-sizing:border-box;vertical-align:top;display:block;max-width:600px;margin:0 auto;clear:both;">
                <div style="box-sizing:border-box;max-width:600px;margin:0 auto;display:block;padding:24px;">

                    {{-- ── Logo ──────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;padding:20px 20px 28px;text-align:center;">
                        <a href="{{ $platform_url }}"
                            style="color:{{ $brand_color }};text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $platform_name }}" style="max-width:180px;max-height:45px;">
                        </a>
                    </div>

                    {{-- ── Hero banner ────────────────────────────────────── --}}
                    <div style="background:linear-gradient(135deg,#1e1b4b 0%,#1e3a5f 50%,#0c2340 100%);border-radius:16px 16px 0 0;padding:48px 40px 40px;text-align:center;border-top:3px solid {{ $brand_color }};">

                        {{-- Sparkle badge --}}
                        <div style="margin:0 0 20px;">
                            <span style="display:inline-block;background:linear-gradient(90deg,{{ $brand_color }},{{ $brand_secondary }});color:#ffffff;font-size:11px;font-weight:700;letter-spacing:2px;padding:6px 20px;border-radius:30px;text-transform:uppercase;">
                                ✦ Platform Upgrade
                            </span>
                        </div>

                        {{-- Headline --}}
                        <h1 style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:32px;font-weight:700;color:#ffffff;margin:0 0 12px;padding:0;line-height:1.2;">
                            Something better<br>
                            <span style="color:{{ $brand_color }};">is waiting for you.</span>
                        </h1>

                        <p style="margin:0 0 8px;color:#94a3b8;font-size:15px;line-height:1.6;">
                            We've rebuilt everything from the ground up — <br>faster, smarter, and designed around <em>you</em>.
                        </p>
                    </div>

                    {{-- ── Main card ──────────────────────────────────────── --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-radius:0 0 16px 16px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:40px 44px 36px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 18px;font-size:16px;font-weight:normal;color:#1e293b;">
                                    Hi <strong>{{ $user->first_name }}</strong>,
                                </p>

                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;line-height:1.7;">
                                    We've been working hard behind the scenes to bring you a completely redesigned
                                    <strong>{{ $platform_name }}</strong>, and today, we're opening the doors for you to
                                    step inside.
                                </p>

                                <p style="margin:0 0 28px;font-weight:normal;color:#374151;font-size:15px;line-height:1.7;">
                                    Your existing account has been migrated. All you need to do is
                                    <strong>set your new password</strong> to gain instant access to everything we've built
                                    for you.
                                </p>

                                {{-- Feature highlights --}}
                                <div style="background-color:#f8fafc;border-radius:12px;padding:24px 28px;margin:0 0 28px;">
                                    <p style="margin:0 0 16px;font-size:11px;font-weight:700;color:{{ $brand_secondary }};text-transform:uppercase;letter-spacing:1.5px;">
                                        What's new for you
                                    </p>

                                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="padding:6px 0;vertical-align:top;width:28px;">
                                                <span style="display:inline-block;width:20px;height:20px;background-color:#ecfdf5;border-radius:50%;text-align:center;line-height:20px;font-size:11px;">✓</span>
                                            </td>
                                            <td style="padding:6px 0 6px 8px;font-size:14px;color:#374151;vertical-align:top;">
                                                <strong>Redesigned dashboard</strong> — everything you need at a glance
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:6px 0;vertical-align:top;width:28px;">
                                                <span style="display:inline-block;width:20px;height:20px;background-color:#ecfdf5;border-radius:50%;text-align:center;line-height:20px;font-size:11px;">✓</span>
                                            </td>
                                            <td style="padding:6px 0 6px 8px;font-size:14px;color:#374151;vertical-align:top;">
                                                <strong>Better Organization</strong> — see reports and resources in a central hub
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:6px 0;vertical-align:top;width:28px;">
                                                <span style="display:inline-block;width:20px;height:20px;background-color:#ecfdf5;border-radius:50%;text-align:center;line-height:20px;font-size:11px;">✓</span>
                                            </td>
                                            <td style="padding:6px 0 6px 8px;font-size:14px;color:#374151;vertical-align:top;">
                                                <strong>Real-time updates</strong> — track your orders and projects live
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:6px 0;vertical-align:top;width:28px;">
                                                <span style="display:inline-block;width:20px;height:20px;background-color:#ecfdf5;border-radius:50%;text-align:center;line-height:20px;font-size:11px;">✓</span>
                                            </td>
                                            <td style="padding:6px 0 6px 8px;font-size:14px;color:#374151;vertical-align:top;">
                                                <strong>All your history</strong> — orders, reports, and invoices preserved
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                {{-- CTA Button --}}
                                <div style="text-align:center;margin:0 0 20px;">
                                    <a href="{{ $reset_url }}"
                                        style="text-decoration:none;color:#ffffff;background:linear-gradient(135deg,{{ $brand_color }} 0%,#d4267a 100%);padding:16px 52px;line-height:1;font-weight:700;font-size:16px;text-align:center;display:inline-block;border-radius:10px;letter-spacing:0.3px;"
                                        target="_blank">
                                        Set My Password &amp; Explore →
                                    </a>
                                </div>

                                {{-- Expiry note --}}
                                <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;text-align:center;">
                                    This link expires in <strong>7 days</strong>. Need a new one? Contact
                                    <a href="mailto:{{ $support_email }}" style="color:{{ $brand_color }};text-decoration:none;">{{ $support_email }}</a>.
                                </p>

                                <hr style="border:none;border-top:1px solid #f1f5f9;margin:0 0 24px;">

                                {{-- Account info --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                                    <tr>
                                        <td style="padding:14px 20px;border-right:1px solid #e2e8f0;">
                                            <p style="margin:0 0 3px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.8px;">Your Name</p>
                                            <p style="margin:0;font-size:13px;color:#1e293b;font-weight:600;">{{ $user->first_name }} {{ $user->last_name }}</p>
                                        </td>
                                        <td style="padding:14px 20px;">
                                            <p style="margin:0 0 3px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.8px;">Login Email</p>
                                            <p style="margin:0;font-size:13px;color:#1e293b;font-weight:600;">{{ $user->email }}</p>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Help note --}}
                                <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;text-align:center;line-height:1.7;">
                                    Questions? We're here. Reply to this email or reach out through the<br>
                                    support section once you're logged in.
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- ── Footer ─────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;width:100%;clear:both;padding:24px 20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#475569;">
                            &copy; {{ date('Y') }} <strong style="color:#94a3b8;">{{ $platform_name }}</strong>. All rights reserved.
                        </p>
                        <p style="margin:0;font-size:11px;color:#334155;">
                            This email was sent to <span style="color:#64748b;">{{ $user->email }}</span> because we are migrating your account to our new platform.
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
