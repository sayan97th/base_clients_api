<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Invitation — {{ config('app.name') }}</title>
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

                    {{-- ── Logo ──────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                        <a href="{{ config('app.frontend_url') }}"
                            style="color:{{ $brand_color }};text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $app_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- ── Main card ──────────────────────────────────────── --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:4px;border-top-style:solid;border-top-color:{{ $brand_color }};border-radius:6px;overflow:hidden;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0 40px 36px;">

                                {{-- Type badge --}}
                                <div style="text-align:center;padding:28px 0 16px;">
                                    <span style="display:inline-block;background-color:{{ $brand_bg }};color:{{ $brand_color }};font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                        TEAM INVITATION
                                    </span>
                                </div>

                                {{-- Heading --}}
                                <h1 align="center"
                                    style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.25em;color:#111827;display:block;margin:0 0 6px;padding:0;font-size:22px;font-weight:600;">
                                    You've been invited to join a team
                                </h1>

                                {{-- Sub-heading --}}
                                <p align="center"
                                    style="margin:0 0 28px;font-weight:normal;color:#6b7280;font-size:13px;">
                                    Start collaborating with your team at <strong style="color:#374151;">{{ $organization_name }}</strong>
                                </p>

                                <hr style="border:none;border-top:1px solid #f3e8ef;margin:0 0 24px;">

                                {{-- Greeting --}}
                                <p style="margin:0 0 16px;font-weight:normal;color:#374151;font-size:15px;">
                                    Hello,
                                </p>

                                {{-- Invitation message --}}
                                <p style="margin:0 0 20px;font-weight:normal;color:#374151;font-size:15px;line-height:1.6;">
                                    <strong>{{ $inviter_name }}</strong> has invited you to join the
                                    <strong>{{ $team_name }}</strong> team at
                                    <strong>{{ $organization_name }}</strong>.
                                    @if (!$is_existing_user)
                                        You will need to create an account to accept this invitation and start
                                        collaborating with your team.
                                    @else
                                        Click the button below to accept the invitation and start collaborating.
                                    @endif
                                </p>

                                {{-- Invitation details --}}
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0 0 28px;background-color:#fdf2f8;border-radius:6px;border:1px solid #f3e8ef;">
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3e8ef;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Team
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;font-weight:600;">
                                                {{ $team_name }}
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3e8ef;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Organization
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;font-weight:600;">
                                                {{ $organization_name }}
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;border-bottom:1px solid #f3e8ef;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Invited By
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;font-weight:600;">
                                                {{ $inviter_name }}
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 20px;">
                                            <p style="margin:0 0 2px;font-size:11px;font-weight:700;color:#c084a8;text-transform:uppercase;letter-spacing:0.5px;">
                                                Expires On
                                            </p>
                                            <p style="margin:0;font-size:13px;color:#374151;font-weight:600;">
                                                {{ $expires_at }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                {{-- CTA button --}}
                                <div style="box-sizing:border-box;text-align:center;margin:0 0 10px;">
                                    <a href="{{ $accept_url }}"
                                        style="text-decoration:none;color:#ffffff;background-color:{{ $brand_color }};padding:12px 48px;line-height:28px;font-weight:600;font-size:15px;text-align:center;display:inline-block;border-radius:6px;"
                                        target="_blank">
                                        @if (!$is_existing_user)
                                            Create Account &amp; Join Team
                                        @else
                                            Accept Invitation
                                        @endif
                                    </a>
                                </div>

                                {{-- Security note --}}
                                <p style="margin:24px 0 0;font-weight:normal;font-size:12px;color:#9ca3af;text-align:center;line-height:1.6;">
                                    If you did not expect this invitation, you can safely ignore this email.<br>
                                    This invitation expires on <strong>{{ $expires_at }}</strong>.
                                </p>

                            </td>
                        </tr>
                    </table>

                    {{-- ── Footer ─────────────────────────────────────────── --}}
                    <div style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#9ca3af;padding:20px;text-align:center;">
                        <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">
                            &copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
