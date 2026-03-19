{{ $appName }} — Password Reset

Hello {{ $recipientName }},

This message contains a secure link to reset your password.

Reset link: {{ $resetUrl }}
Expiration: {{ (int) $expiresMinutes }} minutes from the time this message was sent.

If you did not request a password reset, no action is required.

@if(!empty($appUrl))
System access: {{ $appUrl }}
@endif

This is an automated message. Please do not reply.
@if(!empty($fromAddress))
For assistance: {{ $fromAddress }}
@endif

© {{ date('Y') }} {{ $appName }}

