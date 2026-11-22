<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invitation to join the administration team, with a signed link to set a password. */
class TeamInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $member, public string $link, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You’re invited to the '.setting('branding.site_name', 'MusicDesk').' admin team');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-invite', with: ['site' => setting('branding.site_name', 'MusicDesk')]);
    }
}
