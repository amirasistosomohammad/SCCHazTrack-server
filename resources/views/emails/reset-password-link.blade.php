<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="color-scheme" content="light only" />
    <title>{{ $appName }} — Password Reset</title>
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
      .btnWrap { text-align: center; margin: 12px 0 12px; }
      .btn { display: inline-block; padding: 12px 18px; border-radius: 4px; background: #0d7a3a; color: #ffffff !important; font-weight: 700; text-decoration: none; }
      .meta { font-size: 13px; color: #111827; }
      .small { font-size: 12px; line-height: 1.5; color: #374151; }
      .url { word-break: break-all; }
      .footer { padding: 14px 20px 18px; border-top: 1px solid #e5e7eb; background: #f9fafb; }
    </style>
  </head>
  <body>
    <div class="preheader">
      Password reset link for {{ $appName }} (expires in {{ (int) $expiresMinutes }} minutes).
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
              This email contains a secure link to reset your password.
            </p>

            <div class="hr"></div>

            <div class="label" style="margin: 0 0 6px;">Password reset</div>
            <div class="btnWrap">
              <a class="btn" href="{{ $resetUrl }}">Reset password</a>
            </div>

            <p class="meta" style="margin: 0 0 10px;">
              <strong>Expiration:</strong> {{ (int) $expiresMinutes }} minutes from the time this message was sent.
            </p>

            <p class="muted small" style="margin: 0 0 6px;">
              If you did not request a password reset, no action is required.
            </p>

            <p class="muted small" style="margin: 0 0 6px;">
              If the button does not work, copy and paste this link into your browser:
            </p>
            <p class="small url" style="margin: 0;">
              <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
            </p>

            @if(!empty($appUrl))
              <div class="hr"></div>
              <p class="small" style="margin: 0;">
                System access: <a href="{{ $appUrl }}">{{ $appUrl }}</a>
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

