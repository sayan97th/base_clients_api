<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {{ $platform_name }}</title>
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
                                                    NEW ACCOUNT
                                                </span>
                                            </div>

                                            {{-- Heading --}}
                                            <h1 align="center"
                                                style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#000;display:block;margin:10px 0 5px;padding:0;font-size:22px;font-weight:500;">
                                                Welcome to BASE Ordering Portal!
                                            </h1>

                                            <p align="center"
                                                style="margin:0 0 25px;font-weight:normal;color:#868e96;font-size:13px;">
                                                Your account has been created by our team
                                            </p>

                                            {{-- Greeting --}}
                                            <p style="margin:0 0 15px;font-weight:normal;">
                                                Hello <strong>{{ $user->first_name }}</strong>,
                                            </p>

                                            <p style="margin:0 0 20px;font-weight:normal;">
                                                An administrator has set up an account for you on
                                                <strong>{{ $platform_name }}</strong>. You're ready to get started —
                                                use the button below to set your password and sign in for the first time.
                                            </p>

                                            {{-- Account details table --}}
                                            <div style="box-sizing:border-box;padding:0;color:#343a40;margin:20px 0;">
                                                <table cellpadding="8" cellspacing="0"
                                                    style="margin:0;box-sizing:border-box;width:100%;background-color:#f8f9fa;border-radius:4px;">
                                                    <tr>
                                                        <td
                                                            style="box-sizing:border-box;vertical-align:top;text-align:left;border-bottom:1px dashed #e9ecef;font-weight:500;color:#868e96;width:40%;">
                                                            Name
                                                        </td>
                                                        <td
                                                            style="box-sizing:border-box;vertical-align:top;text-align:right;border-bottom:1px dashed #e9ecef;">
                                                            {{ $user->first_name }} {{ $user->last_name }}
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td
                                                            style="box-sizing:border-box;vertical-align:top;text-align:left;font-weight:500;color:#868e96;width:40%;{{ $temporary_password !== null ? 'border-bottom:1px dashed #e9ecef;' : '' }}">
                                                            Email
                                                        </td>
                                                        <td
                                                            style="box-sizing:border-box;vertical-align:top;text-align:right;{{ $temporary_password !== null ? 'border-bottom:1px dashed #e9ecef;' : '' }}">
                                                            {{ $user->email }}
                                                        </td>
                                                    </tr>
                                                    @if ($temporary_password !== null)
                                                        <tr>
                                                            <td
                                                                style="box-sizing:border-box;vertical-align:top;text-align:left;font-weight:500;color:#868e96;width:40%;">
                                                                Temporary Password
                                                            </td>
                                                            <td
                                                                style="box-sizing:border-box;vertical-align:top;text-align:right;">
                                                                <span
                                                                    style="font-family:monospace,'Courier New',Courier;font-size:13px;font-weight:700;color:#343a40;">{{ $temporary_password }}</span>
                                                            </td>
                                                        </tr>
                                                    @endif
                                                </table>
                                            </div>

                                            @if ($temporary_password !== null)
                                                {{-- Temporary password warning --}}
                                                <div
                                                    style="box-sizing:border-box;padding:12px 16px;background-color:#fce7f3;border-left:4px solid #ec3c89;border-radius:4px;margin:0 0 20px;">
                                                    <p style="margin:0;font-size:13px;color:#9d174d;">
                                                        Please change this password after your first login.
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- CTA Button --}}
                                            <div style="box-sizing:border-box;text-align:center;margin:30px 0 10px;">
                                                <a href="{{ $reset_url }}"
                                                    style="text-decoration:none;color:#ffffff;background-color:#ec3c89;padding:10px 45px;line-height:28px;font-weight:500;font-size:16px;text-align:center;display:inline-block;border-radius:5px;"
                                                    target="_blank">
                                                    Set Your Password &amp; Sign In
                                                </a>
                                            </div>

                                            {{-- Link expiry note --}}
                                            <p
                                                style="margin:10px 0 20px;font-weight:normal;font-size:12px;color:#868e96;text-align:center;">
                                                This link expires in 60 minutes.
                                            </p>

                                            {{-- Help text --}}
                                            <p
                                                style="margin:20px 0 0;font-weight:normal;font-size:12px;color:#868e96;text-align:center;">
                                                If you have any questions or need assistance, reply to this email or
                                                contact our support team.
                                            </p>

                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>

                    {{-- Footer --}}
                    <div
                        style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#868e96;padding:20px;text-align:center;">
                        <p style="margin:0 0 4px;font-size:12px;color:#868e96;">
                            &copy; {{ date('Y') }} {{ $platform_name }}. All rights reserved.
                        </p>
                        <p style="margin:0;font-size:11px;color:#adb5bd;">
                            You received this email because an administrator created an account for you on
                            {{ $platform_name }}.
                        </p>
                    </div>

                </div>
            </td>
            <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        </tr>
    </table>
</body>

</html>
