<?php

namespace App\Mail;

use App\Models\EmailVerification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyEmailAddress extends Mailable
{
    public function __construct(public EmailVerification $verification)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your e-mail address');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            text: 'emails.verify-email-text',
            with: [
                'email' => $this->verification->email,
                'verifyUrl' => $this->verification->link(),
                'blockUrl' => $this->verification->link(true),
                'expiresAt' => $this->verification->expiresAt()->utc(),
                'appName' => config('app.name'),
            ],
        );
    }
}
