<?php

namespace App\Mail;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Ticket emails: new ticket / customer reply to staff, staff reply to the customer. */
class SupportTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public SupportMessage $message, public bool $toStaff, public bool $isNew) {}

    public function envelope(): Envelope
    {
        $prefix = $this->toStaff ? ($this->isNew ? 'New ticket' : 'Customer replied') : 'Reply from support';

        return new Envelope(subject: "[{$this->ticket->number}] {$prefix}: {$this->ticket->subject}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support-ticket', with: [
            'url' => $this->toStaff ? route('admin.support.show', $this->ticket) : route('dashboard.support', ['t' => $this->ticket->number]),
            'site' => setting('branding.site_name', 'MusicDesk'),
            'product' => setting('branding.product_name', 'LabelDesk'),
        ]);
    }
}
