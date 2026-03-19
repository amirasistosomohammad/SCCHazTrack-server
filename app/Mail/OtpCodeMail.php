<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $appName,
        public string $recipientName,
        public string $code,
        public string $purpose,
        public int $expiresMinutes,
    ) {}

    public function envelope(): Envelope
    {
        $appName = $this->publicAppName();
        $purposeLabel = match ($this->purpose) {
            'password_reset' => 'Password Reset',
            'email_verify' => 'Email Verification',
            default => 'Account Access',
        };

        $subject = "{$appName} — One-Time Passcode ({$purposeLabel})";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $appName = $this->publicAppName();

        return new Content(
            view: 'emails.otp-code',
            text: 'emails.otp-code-text',
            with: [
                'appName' => $appName,
                'recipientName' => $this->recipientName,
                'code' => $this->code,
                'purpose' => $this->purpose,
                'expiresMinutes' => $this->expiresMinutes,
                'appUrl' => config('app.url'),
                'fromAddress' => config('mail.from.address'),
            ],
        );
    }

    private function publicAppName(): string
    {
        $name = trim($this->appName);

        if ($name === '' || mb_strtolower($name) === 'laravel') {
            return 'SCC HazTrack';
        }

        return $name;
    }
}

