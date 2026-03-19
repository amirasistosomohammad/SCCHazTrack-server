<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $appName,
        public string $recipientName,
        public string $resetUrl,
        public int $expiresMinutes,
    ) {}

    public function envelope(): Envelope
    {
        $appName = $this->publicAppName();

        return new Envelope(subject: "{$appName} — Password Reset Link");
    }

    public function content(): Content
    {
        $appName = $this->publicAppName();

        return new Content(
            view: 'emails.reset-password-link',
            text: 'emails.reset-password-link-text',
            with: [
                'appName' => $appName,
                'recipientName' => $this->recipientName,
                'resetUrl' => $this->resetUrl,
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

