<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
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

                    {{-- Logo Section --}}
                    <div style="margin:0;box-sizing:border-box;padding:0 20px 20px;text-align:center;">
                        <a href="{{ config('app.frontend_url') }}" style="color:#007bff;text-decoration:none;"
                            target="_blank">
                            <img src="{{ config('app.logo_url', config('app.url') . '/images/base-logo.png') }}"
                                alt="{{ config('app.name') }}" style="max-width:200px;max-height:50px;">
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

                                            {{-- Heading --}}
                                            <h1 align="center"
                                                style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;line-height:1.2em;color:#000;display:block;margin:10px 0 5px;padding:0;font-size:22px;font-weight:500;">
                                                {{ $title }}
                                            </h1>

                                            <p align="center"
                                                style="margin:0 0 25px;font-weight:normal;color:#868e96;font-size:13px;">
                                                Exciting updates from {{ $app_name }}
                                            </p>

                                            {{-- Greeting --}}
                                            <p style="margin:0 0 15px;font-weight:normal;">
                                                Hello {{ $user_name }},
                                            </p>

                                            {{-- Body Content --}}
                                            <div style="margin:0 0 20px;font-weight:normal;line-height:1.6;">
                                                {!! nl2br(e($body)) !!}
                                            </div>

                                            {{-- CTA Button --}}
                                            @if ($action_url)
                                                <div style="box-sizing:border-box;text-align:center;margin:30px 0 10px;">
                                                    <a href="{{ $action_url }}"
                                                        style="text-decoration:none;color:#ffffff;background-color:#ec3c89;padding:10px 45px;line-height:28px;font-weight:500;font-size:16px;text-align:center;display:inline-block;border-radius:5px;"
                                                        target="_blank">
                                                        {{ $action_label }}
                                                    </a>
                                                </div>
                                            @endif

                                            {{-- Secondary Note --}}
                                            <p
                                                style="margin:20px 0 0;font-weight:normal;font-size:12px;color:#868e96;text-align:center;">
                                                You received this email because you are subscribed to product updates.
                                                You can update your preferences in your account settings.
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
