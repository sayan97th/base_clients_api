<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Welcome to {{ $platform_name }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        table { border-collapse: collapse; }
        img { border: 0; display: block; }
        a { text-decoration: none; }
        @media only screen and (max-width: 600px) {
            .email-wrapper { width: 100% !important; }
            .email-body { padding: 24px 16px !important; }
            .details-card { padding: 16px !important; }
            .cta-button { min-width: 200px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:40px 16px;">
    <tr>
        <td align="center">

            {{-- Email wrapper --}}
            <table role="presentation" class="email-wrapper" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

                {{-- Header --}}
                <tr>
                    <td style="background-color:#0f172a;padding:28px 40px;text-align:center;">
                        <p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">
                            {{ $platform_name }}
                        </p>
                    </td>
                </tr>

                {{-- Hero section --}}
                <tr>
                    <td class="email-body" style="background-color:#ffffff;padding:48px 40px 32px;text-align:center;">

                        {{-- Icon --}}
                        <div style="display:inline-block;background-color:#0d9488;border-radius:50%;width:72px;height:72px;line-height:72px;text-align:center;margin-bottom:24px;">
                            <span style="font-size:32px;line-height:72px;color:#ffffff;">&#10003;</span>
                        </div>

                        <h1 style="margin:0 0 12px;font-size:26px;font-weight:700;color:#0f172a;">
                            Welcome to {{ $platform_name }}!
                        </h1>
                        <p style="margin:0 0 32px;font-size:16px;line-height:1.6;color:#64748b;">
                            Your account has been created by our team.<br>You're all set to get started.
                        </p>

                        {{-- Account details card --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:32px;text-align:left;">
                            <tr>
                                <td style="padding:20px 24px;">
                                    <p style="margin:0 0 4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;">Account Details</p>
                                </td>
                            </tr>

                            {{-- Name row --}}
                            <tr>
                                <td style="padding:0 24px 16px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="font-size:13px;color:#64748b;width:140px;vertical-align:top;padding-top:2px;">Name</td>
                                            <td style="font-size:14px;font-weight:600;color:#0f172a;">{{ $user->first_name }} {{ $user->last_name }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            {{-- Email row --}}
                            <tr>
                                <td style="padding:0 24px 16px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="font-size:13px;color:#64748b;width:140px;vertical-align:top;padding-top:2px;">Email</td>
                                            <td style="font-size:14px;font-weight:600;color:#0f172a;">{{ $user->email }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            @if ($temporary_password !== null)
                            {{-- Temporary password row --}}
                            <tr>
                                <td style="padding:0 24px 16px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="font-size:13px;color:#64748b;width:140px;vertical-align:top;padding-top:2px;">Temporary Password</td>
                                            <td>
                                                <span style="font-family:monospace,'Courier New',Courier;font-size:14px;font-weight:700;color:#0f172a;background-color:#f1f5f9;padding:3px 8px;border-radius:4px;">{{ $temporary_password }}</span>
                                                <p style="margin:6px 0 0;font-size:12px;color:#f59e0b;">Please change this password after your first login.</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            @endif

                        </table>

                        {{-- CTA Button --}}
                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 12px;">
                            <tr>
                                <td style="border-radius:8px;background-color:#0d9488;">
                                    <a href="{{ $reset_url }}"
                                       class="cta-button"
                                       style="display:inline-block;min-width:260px;padding:14px 32px;font-size:15px;font-weight:600;color:#ffffff;background-color:#0d9488;border-radius:8px;text-align:center;text-decoration:none;">
                                        Set Your Password &amp; Sign In
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 32px;font-size:12px;color:#94a3b8;">This link expires in 60 minutes.</p>

                        {{-- Help text --}}
                        <p style="margin:0;font-size:14px;line-height:1.6;color:#64748b;">
                            If you have any questions or need assistance, reply to this email or contact our support team.
                        </p>

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background-color:#0f172a;padding:28px 40px;text-align:center;">
                        <p style="margin:0 0 8px;font-size:13px;color:#94a3b8;">
                            &copy; {{ date('Y') }} {{ $platform_name }}. All rights reserved.
                        </p>
                        <p style="margin:0 0 10px;font-size:12px;color:#64748b;line-height:1.5;">
                            You received this email because an administrator created an account for you on {{ $platform_name }}.
                        </p>
                        <a href="{{ $platform_url }}" style="font-size:12px;color:#0d9488;">{{ $platform_url }}</a>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
