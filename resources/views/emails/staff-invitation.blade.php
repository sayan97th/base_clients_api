<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Invitation</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
        .wrapper { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background-color: #1a1a2e; padding: 32px 40px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; letter-spacing: 0.5px; }
        .body { padding: 40px; color: #333333; }
        .body p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        .role-badge { display: inline-block; background-color: #e8f0fe; color: #1a73e8; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; text-transform: capitalize; }
        .cta-wrapper { text-align: center; margin: 32px 0; }
        .cta-button { display: inline-block; background-color: #1a73e8; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 6px; font-size: 15px; font-weight: 600; }
        .expiry-note { background-color: #fff8e1; border-left: 4px solid #fbc02d; padding: 12px 16px; border-radius: 4px; font-size: 13px; color: #555; margin-top: 24px; }
        .footer { background-color: #f9f9f9; padding: 20px 40px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eeeeee; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>You've been invited</h1>
        </div>
        <div class="body">
            <p>Hi there,</p>
            <p>
                <strong>{{ $inviter_name }}</strong> has invited you to join the platform as a
                <span class="role-badge">{{ $role_label }}</span>.
            </p>
            <p>
                Click the button below to create your account and get started. This invitation
                link expires on <strong>{{ $expiry_date }}</strong>.
            </p>
            <div class="cta-wrapper">
                <a href="{{ $accept_url }}" class="cta-button">Accept Invitation</a>
            </div>
            <p>
                Or copy and paste this URL into your browser:<br>
                <a href="{{ $accept_url }}" style="color:#1a73e8; word-break:break-all;">{{ $accept_url }}</a>
            </p>
            <div class="expiry-note">
                ⚠️ This invitation link expires in 7 days, on {{ $expiry_date }}. If you did not
                expect this invitation, you can safely ignore this email.
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
