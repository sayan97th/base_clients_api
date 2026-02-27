<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Invitation</title>
</head>
<body style="font-family:proxima-nova,'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;height:100%;line-height:22px;margin:0;padding:0;box-sizing:border-box;background-color:#f4f4f7;width:100%;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;box-sizing:border-box;width:100%;background-color:#f4f4f7;">
    <tr>
        <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
        <td width="600" style="box-sizing:border-box;vertical-align:top;display:block;max-width:600px;margin:0 auto;clear:both;">
            <div style="box-sizing:border-box;max-width:600px;margin:0 auto;display:block;padding:24px;">

                {{-- Logo Section --}}
                <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                    <a href="{{ config('app.frontend_url') }}" style="color:#007bff;text-decoration:none;" target="_blank">
                        <img src="{{ config('app.logo_url', config('app.url') . '/images/logo.png') }}" alt="{{ config('app.name') }}" style="max-width:200px;max-height:50px;">
                    </a>
                </div>

                {{-- Main Content Card --}}
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;box-sizing:border-box;background-color:#ffffff;border-top-width:3px;border-top-style:solid;border-top-color:#ec3c89;border-radius:4px;">
                    <tr>
                        <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:30px 40px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;box-sizing:border-box;">
                                <tr>
                                    <td style="margin:0;box-sizing:border-box;vertical-align:top;padding:0;">

                                        {{-- Heading --}}
                                        <h1 align="center" style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#000;display:block;margin:10px 0 5px;padding:0;font-size:22px;font-weight:500;">
                                            Team Invitation
                                        </h1>

                                        <p align="center" style="margin:0 0 25px;font-weight:normal;color:#868e96;font-size:13px;">
                                            You have been invited to collaborate
                                        </p>

                                        {{-- Greeting --}}
                                        <p style="margin:0 0 15px;font-weight:normal;">
                                            Hello,
                                        </p>

                                        {{-- Invitation Message --}}
                                        <p style="margin:0 0 15px;font-weight:normal;">
                                            <strong>{{ $inviter_name }}</strong> has invited you to join the <strong>{{ $team_name }}</strong> team at <strong>{{ $organization_name }}</strong>.
                                        </p>

                                        @if (!$is_existing_user)
                                            <p style="margin:0 0 15px;font-weight:normal;">
                                                You will need to create an account to accept this invitation and start collaborating with your team.
                                            </p>
                                        @else
                                            <p style="margin:0 0 15px;font-weight:normal;">
                                                Click the button below to accept the invitation and join the team.
                                            </p>
                                        @endif

                                        {{-- Invitation Details --}}
                                        <div style="box-sizing:border-box;padding:0;color:#343a40;margin:20px 0;">
                                            <table cellpadding="8" cellspacing="0" style="margin:0;box-sizing:border-box;width:100%;background-color:#f8f9fa;border-radius:4px;">
                                                <tr>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:left;border-bottom:1px dashed #e9ecef;font-weight:500;color:#868e96;width:40%;">
                                                        Team
                                                    </td>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:right;border-bottom:1px dashed #e9ecef;">
                                                        {{ $team_name }}
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:left;border-bottom:1px dashed #e9ecef;font-weight:500;color:#868e96;width:40%;">
                                                        Organization
                                                    </td>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:right;border-bottom:1px dashed #e9ecef;">
                                                        {{ $organization_name }}
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:left;border-bottom:1px dashed #e9ecef;font-weight:500;color:#868e96;width:40%;">
                                                        Invited By
                                                    </td>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:right;border-bottom:1px dashed #e9ecef;">
                                                        {{ $inviter_name }}
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:left;font-weight:500;color:#868e96;width:40%;">
                                                        Expires On
                                                    </td>
                                                    <td style="box-sizing:border-box;vertical-align:top;text-align:right;">
                                                        {{ $expires_at }}
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>

                                        {{-- CTA Button --}}
                                        <div style="box-sizing:border-box;text-align:center;margin:30px 0 10px;">
                                            <a href="{{ $accept_url }}" style="text-decoration:none;color:#ffffff;background-color:#ec3c89;padding:10px 45px;line-height:28px;font-weight:500;font-size:16px;text-align:center;display:inline-block;border-radius:5px;" target="_blank">
                                                @if (!$is_existing_user)
                                                    Create Account & Join Team
                                                @else
                                                    Accept Invitation
                                                @endif
                                            </a>
                                        </div>

                                        {{-- Secondary Note --}}
                                        <p style="margin:20px 0 0;font-weight:normal;font-size:12px;color:#868e96;text-align:center;">
                                            If you did not expect this invitation, you can safely ignore this email.
                                        </p>

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Footer --}}
                <div style="margin:0;box-sizing:border-box;width:100%;clear:both;color:#868e96;padding:20px;text-align:center;">
                    <p style="margin:0;font-size:12px;color:#868e96;">
                        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                    </p>
                </div>

            </div>
        </td>
        <td style="box-sizing:border-box;vertical-align:top;">&nbsp;</td>
    </tr>
</table>
</body>
</html>
