<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="color-scheme" content="light only" />
    <title>{{ $appName }} — One-Time Passcode</title>
    <style>
      body { margin: 0; padding: 0; background: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, "Noto Sans", "Liberation Sans", sans-serif; color: #111827; }
      a { color: #0b5a2a; text-decoration: underline; }
      .wrap { width: 100%; padding: 24px 12px; }
      .container { max-width: 640px; margin: 0 auto; }
      .preheader { display: none !important; visibility: hidden; opacity: 0; color: transparent; height: 0; width: 0; overflow: hidden; mso-hide: all; }
      .card { background: #ffffff; border: 1px solid #d1d5db; }
      .header { padding: 18px 20px; border-bottom: 4px solid #0d7a3a; }
      .brand { font-weight: 700; font-size: 16px; letter-spacing: 0.01em; color: #111827; }
      .subbrand { margin-top: 4px; font-size: 12px; color: #374151; }
      .content { padding: 18px 20px 10px; }
      .muted { color: #374151; }
      .hr { height: 1px; background: #e5e7eb; margin: 16px 0; }
      .label { font-size: 12px; color: #374151; text-transform: uppercase; letter-spacing: 0.06em; }
      .codeBox { margin: 10px 0 12px; padding: 14px 14px; border: 1px solid #9ca3af; border-left: 4px solid #0d7a3a; background: #f9fafb; text-align: center; }
      .code { font-size: 26px; font-weight: 800; letter-spacing: 0.22em; color: #111827; }
      .meta { font-size: 13px; color: #111827; }
      .footer { padding: 14px 20px 18px; border-top: 1px solid #e5e7eb; background: #f9fafb; }
      .small { font-size: 12px; line-height: 1.5; color: #374151; }
    </style>
  </head>
  <body>
    <div class="preheader">
      Your one-time passcode for {{ $appName }} (expires in {{ (int) $expiresMinutes }} minutes).
    </div>

    <div class="wrap">
      <div class="container">
        <div class="card">
          <div class="header">
            <div class="brand">{{ $appName }}</div>
            <div class="subbrand">System notification — do not reply</div>
          </div>

          <div class="content">
            <p style="margin: 0 0 10px;">
              Hello <strong>{{ $recipientName }}</strong>,
            </p>

            <p class="muted" style="margin: 0;">
              @if(($purpose ?? '') === 'password_reset')
                This email contains your one-time passcode for <strong>Password Reset</strong>.
              @else
                This email contains your one-time passcode for <strong>Email Verification</strong>.
              @endif
            </p>

            <div class="hr"></div>

            <div class="label" style="margin: 0 0 6px;">One-time passcode</div>
            <div class="codeBox" role="presentation" aria-label="One-time passcode">
              <div class="code">{{ $code }}</div>
            </div>

            <p class="meta" style="margin: 0 0 10px;">
              <strong>Expiration:</strong> {{ (int) $expiresMinutes }} minutes from the time this message was sent.
            </p>

            <p class="muted small" style="margin: 0;">
              If you did not request this code, no action is required. Do not share this code with anyone.
            </p>

            @if(!empty($appUrl))
              <div class="hr"></div>
              <p class="small" style="margin: 0;">
                To access the system, visit <a href="{{ $appUrl }}">{{ $appUrl }}</a>.
              </p>
            @endif
          </div>

          <div class="footer">
            <p class="small" style="margin: 0 0 8px;">
              <strong>{{ $appName }}</strong> — automated message.
              @if(!empty($fromAddress))
                For assistance, contact <a href="mailto:{{ $fromAddress }}">{{ $fromAddress }}</a>.
              @endif
            </p>
            <p class="small" style="margin: 0;">
              Please do not reply to this email. Replies are not monitored.
              © {{ date('Y') }} {{ $appName }}.
            </p>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>

