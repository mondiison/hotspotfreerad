<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subscriber->shop->name }}</title>
</head>
@php
    $tenant = $subscriber->shop->tenant;
    $brandColor = $tenant->brand_color ?? '#10b981';
    $isExpired = $stage === \App\Mail\PppoeExpiryReminderMail::STAGE_EXPIRED;
@endphp
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="background-color:{{ $brandColor }}; padding:20px 24px;">
                            <span style="color:#ffffff; font-size:16px; font-weight:600;">{{ $tenant->company_name }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 24px 8px;">
                            <h1 style="margin:0; font-size:20px; color:#18181b;">
                                @if ($stage === \App\Mail\PppoeExpiryReminderMail::STAGE_THREE_DAY)
                                    Your plan expires in 3 days
                                @elseif ($stage === \App\Mail\PppoeExpiryReminderMail::STAGE_ONE_DAY)
                                    Your plan expires tomorrow
                                @else
                                    Your plan has expired
                                @endif
                            </h1>
                            <p style="margin:12px 0 0; font-size:14px; line-height:22px; color:#52525b;">
                                Hi{{ $subscriber->full_name ? ' '.$subscriber->full_name : '' }}, this is a reminder about your
                                <strong>{{ $subscriber->shop->name }}</strong> internet plan.
                                @if ($isExpired)
                                    Your access has been switched off. Pay below to get back online right away.
                                @else
                                    Pay below any time before it expires to keep your access running with no interruption.
                                @endif
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; border-radius:6px;">
                                <tr>
                                    <td style="padding:14px 16px; font-size:13px; color:#71717a;">Account</td>
                                    <td style="padding:14px 16px; font-size:13px; color:#18181b; text-align:right; font-family: monospace;">{{ $subscriber->username }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px 14px; font-size:13px; color:#71717a;">Plan</td>
                                    <td style="padding:0 16px 14px; font-size:13px; color:#18181b; text-align:right;">{{ $subscriber->package->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px 14px; font-size:13px; color:#71717a;">{{ $isExpired ? 'Expired' : 'Expires' }}</td>
                                    <td style="padding:0 16px 14px; font-size:13px; color:#18181b; text-align:right;">{{ $subscriber->expires_at?->format('M j, Y g:i A') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px 14px; font-size:13px; color:#71717a;">Amount due</td>
                                    <td style="padding:0 16px 14px; font-size:16px; font-weight:600; color:#18181b; text-align:right;">{{ $subscriber->package->currency }} {{ number_format($subscriber->package->price, 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 24px 28px;" align="center">
                            <a href="{{ $renewalUrl }}" style="display:inline-block; background-color:{{ $brandColor }}; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:12px 28px; border-radius:6px;">
                                Pay and renew now
                            </a>
                            <p style="margin:16px 0 0; font-size:12px; color:#a1a1aa;">Or copy this link: <br>{{ $renewalUrl }}</p>
                        </td>
                    </tr>
                    @if ($subscriber->shop->contactPhone())
                        <tr>
                            <td style="padding:0 24px 24px; font-size:12px; color:#a1a1aa; text-align:center;">
                                Having trouble? Call {{ $subscriber->shop->contactPhone() }}.
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
