{{ $appName }} — One-Time Passcode

Hello {{ $recipientName }},

@if(($purpose ?? '') === 'password_reset')
Purpose: Password Reset
@else
Purpose: Email Verification
@endif

One-time passcode: {{ $code }}
Expiration: {{ (int) $expiresMinutes }} minutes from the time this message was sent.

If you did not request this code, no action is required. Do not share this code with anyone.

@if(!empty($appUrl))
System access: {{ $appUrl }}
@endif

This is an automated message. Please do not reply.
@if(!empty($fromAddress))
For assistance: {{ $fromAddress }}
@endif

© {{ date('Y') }} {{ $appName }}

