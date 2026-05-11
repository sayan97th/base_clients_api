<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You've been invited to join {{ $platform_name }}</title>
</head>

<body
    style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f4f4f7;width:100%;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:0;box-sizing:border-box;width:100%;background-color:#f4f4f7;">
        <tr>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
            <td width="600"
                style="box-sizing:border-box;vertical-align:top;display:block;max-width:600px;margin:0 auto;clear:both;">
                <div style="box-sizing:border-box;max-width:600px;margin:0 auto;display:block;padding:24px;">

                    {{-- Logo --}}
                    <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                        <a href="{{ $platform_url }}" style="color:#ec3c89;text-decoration:none;" target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ $platform_name }}" style="max-width:200px;max-height:50px;">
                        </a>
                    </div>

                    {{-- Main Content Card --}}
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:3px;border-top-style:solid;border-top-color:#ec3c89;border-radius:4px;">
                        <tr>
                            <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:30px 40px;">
                                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                    style="margin:0;box-sizing:border-box;">
                                    <tr>
                                        <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0;">

                                            {{-- Badge --}}
                                            <div style="text-align:center;margin:0 0 16px;">
                                                <span
                                                    style="display:inline-block;background-color:#fce7f3;color:#ec3c89;font-size:11px;font-weight:700;letter-spacing:1.5px;padding:5px 16px;border-radius:20px;">
                                                    INVITATION
                                                </span>
                                            </div>

                                            {{-- Heading --}}
                                            <h1 align="center"
                                                style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#000;display:block;margin:10px 0 5px;padding:0;font-size:22px;font-weight:500;">
                                                You've been invited!
                                            </h1>

                                            <p align="center"
                                                style="margin:0 0 25px;font-weight:normal;color:#868e96;font-size:13px;">
                                                {{ $inviter_name }} has invited you to join {{ $platform_name }}
                                            </p>

                                            {{-- Greeting --}}
                                            <p style="margin:0 0 15px;font-weight:normal;">
                                                Hello,
                                            </p>

                                            <p style="margin:0 0 20px;font-weight:normal;color:#444;">
                                                You've been invited to create a client account on
                                                <strong>{{ $platform_name }}</strong>. Click the button below to
                                                set up your account and get started.
                                            </p>

                                            {{-- Invite Details --}}
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                                style="margin:0 0 24px;box-sizing:border-box;background-color:#f8f9fa;border-radius:4px;border:1px solid #e9ecef;">
                                                <tr>
                                                    <td style="padding:16px 20px;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="padding:4px 0;">
                                                                    <span
                                                                        style="color:#868e96;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Invited
                                                                        by</span>
                                                                </td>
                                                                <td style="padding:4px 0;text-align:right;">
                                                                    <span
                                                                        style="color:#333;font-size:13px;font-weight:500;">{{ $inviter_name }}</span>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td
                                                                    style="padding:4px 0;border-top:1px solid #e9ecef;">
                                                                    <span
                                                                        style="color:#868e96;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Invitation
                                                                        expires</span>
                                                                </td>
                                                                <td
                                                                    style="padding:4px 0;text-align:right;border-top:1px solid #e9ecef;">
                                                                    <span
                                                                        style="color:#333;font-size:13px;font-weight:500;">{{ $expiry_date }}</span>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>

                                            {{-- CTA Button --}}
                                            <div style="text-align:center;margin:0 0 24px;">
                                                <a href="{{ $accept_url }}"
                                                    style="display:inline-block;background-color:#ec3c89;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 32px;border-radius:4px;letter-spacing:0.3px;"
                                                    target="_blank">
                                                    Accept Invitation &amp; Set Up Account
                                                </a>
                                            </div>

                                            {{-- Link fallback --}}
                                            <p
                                                style="margin:0 0 8px;font-weight:normal;color:#868e96;font-size:12px;text-align:center;">
                                                If the button doesn't work, copy and paste this link into your browser:
                                            </p>
                                            <p
                                                style="margin:0 0 20px;font-weight:normal;font-size:12px;text-align:center;word-break:break-all;">
                                                <a href="{{ $accept_url }}"
                                                    style="color:#ec3c89;text-decoration:none;">{{ $accept_url }}</a>
                                            </p>

                                            {{-- Security note --}}
                                            <p
                                                style="margin:0;font-weight:normal;color:#adb5bd;font-size:12px;text-align:center;">
                                                This invitation link expires on <strong>{{ $expiry_date }}</strong>.
                                                If you did not expect this invitation, you can safely ignore this email.
                                            </p>

                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <div style="margin:20px 0 0;padding:0 20px;text-align:center;">
                        <p style="margin:0;color:#adb5bd;font-size:12px;">
                            &copy; {{ date('Y') }} {{ $platform_name }}. All rights reserved.
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
