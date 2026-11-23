<?php

namespace App\Mail;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One-time code to deactivate LabelDesk on a domain (LabelDesk 4.3.2+), sent to the license owner. */
class DeactivationCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public License $license, public string $domain, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.setting('branding.product_name', 'LabelDesk').' deactivation code');
    }

    public function content(): Content
    {
        $with = [
            'product' => setting('branding.product_name', 'LabelDesk'),
            'site' => setting('branding.site_name', 'MusicDesk'),
            'portalUrl' => route('dashboard.licenses'),
        ];

        return new Content(view: 'emails.deactivation-code', text: 'emails.deactivation-code-text', with: $with);
    }
}
