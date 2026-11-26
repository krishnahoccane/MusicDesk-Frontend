<?php

namespace App\Mail;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Free trial notices: "ending" (3 days left), "ended" (read-only now), "suspended" (read-only period over). */
class TrialNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public const STAGES = ['ending', 'ended', 'suspended'];

    public function __construct(public License $license, public string $stage) {}

    public function envelope(): Envelope
    {
        $product = setting('branding.product_name', 'LabelDesk');
        $days = $this->license->trialDaysLeft();

        return new Envelope(subject: match ($this->stage) {
            'ending' => "Your {$product} free trial ends in {$days} ".($days === 1 ? 'day' : 'days'),
            'ended' => "Your {$product} free trial has ended",
            default => "Your {$product} trial license {$this->license->number} is suspended",
        });
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial-notice', with: [
            'buyUrl' => route('checkout', $this->license->plan?->code ?? 'starter'),
            'product' => setting('branding.product_name', 'LabelDesk'),
            'site' => setting('branding.site_name', 'MusicDesk'),
            'lockDate' => $this->license->expires_at?->copy()->addDays(License::READ_ONLY_DAYS),
        ]);
    }
}
