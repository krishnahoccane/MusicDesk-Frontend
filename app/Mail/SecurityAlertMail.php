<?php

namespace App\Mail;

use App\Models\LicenseAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the site admins: a LabelDesk install failed its license check. */
class SecurityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LicenseAlert $alert, public bool $first) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->first ? 'Security: license check failed on ' : 'Security: still failing on ').$this->alert->domain);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.security-alert', with: [
            'statusUrl' => route('admin.license-status', ['q' => $this->alert->domain]),
            'reason' => LicenseAlert::reasonLabel($this->alert->last_reason),
            'known' => $this->alert->knownActivation(),
            'site' => setting('branding.site_name', 'MusicDesk'),
        ]);
    }
}
