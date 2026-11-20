<?php

namespace App\Mail;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public License $license, public int $days) {}

    public function envelope(): Envelope
    {
        $product = setting('branding.product_name', 'LabelDesk');

        return new Envelope(subject: "Your {$product} license {$this->license->number} renews in {$this->days} ".($this->days === 1 ? 'day' : 'days'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.license-expiring', with: [
            'renewUrl' => route('licenses.buy', [$this->license, 'renewal']),
            'product' => setting('branding.product_name', 'LabelDesk'),
            'site' => setting('branding.site_name', 'MusicDesk'),
        ]);
    }
}
