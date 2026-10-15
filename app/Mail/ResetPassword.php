<?php

namespace App\Mail;

use App\Models\PasswordReset;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResetPassword extends Mailable
{
    public function __construct(public string $name, public string $token)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
            text: 'emails.reset-password-text',
            with: [
                'name' => $this->name,
                'resetUrl' => PasswordReset::link($this->token),
                'minutes' => PasswordReset::VALID_MINUTES,
                'appName' => config('app.name'),
            ],
        );
    }
}
