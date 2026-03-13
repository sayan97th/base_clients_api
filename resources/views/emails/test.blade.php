<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Email</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; padding: 40px; }
        .header { font-size: 22px; font-weight: bold; color: #333333; margin-bottom: 16px; }
        .body { font-size: 16px; color: #555555; line-height: 1.6; }
        .footer { margin-top: 32px; font-size: 12px; color: #aaaaaa; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Hello, {{ $recipient_name }}!</div>
        <div class="body">
            <p>{{ $message_body }}</p>
            <p>If you received this email, it means your mail configuration is working correctly.</p>
        </div>
        <div class="footer">
            Sent by {{ $app_name }}
        </div>
    </div>
</body>
</html>
