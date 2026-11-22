<?php

namespace App\Services\Support;

use App\Mail\SupportTicketMail;
use App\Models\License;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Support tickets: open, reply (customer or staff, with attachments), status changes
 * and the emails that go with them. Attachments are stored privately and only served
 * through the portal / admin download routes.
 */
class SupportDesk
{
    public const MAX_FILES = 5;

    public const MAX_FILE_KB = 10240;

    public const FILE_TYPES = 'png,jpg,jpeg,gif,webp,pdf,txt,log,json,zip,csv';

    /** @param  array{subject: string, body: string, category: string, priority: string, license_id?: ?int, domain?: ?string}  $data */
    public function open(User $customer, array $data, array $files = []): SupportTicket
    {
        [$ticket, $message] = DB::transaction(function () use ($customer, $data, $files) {
            $ticket = SupportTicket::create([
                'number' => 'TMP-'.Str::random(8),
                'organisation_id' => $customer->organisation_id,
                'user_id' => $customer->id,
                'license_id' => $data['license_id'] ?? null,
                'domain' => $data['domain'] ?? null,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'subject' => $data['subject'],
                'status' => SupportTicket::OPEN,
                'last_reply_at' => now(),
            ]);
            $ticket->update(['number' => 'TCK-'.(4800 + $ticket->id)]);

            return [$ticket, $this->addMessage($ticket, $customer, $data['body'], false, false, $files)];
        });

        $this->mailStaff($ticket, $message, true);

        return $ticket;
    }

    public function customerReply(SupportTicket $ticket, User $customer, string $body, array $files = []): SupportMessage
    {
        $message = DB::transaction(function () use ($ticket, $customer, $body, $files) {
            $message = $this->addMessage($ticket, $customer, $body, false, false, $files);
            $ticket->update(['status' => SupportTicket::OPEN, 'last_reply_at' => now(), 'resolved_at' => null]);

            return $message;
        });
        $this->mailStaff($ticket, $message, false);

        return $message;
    }

    /** Staff reply or internal note. $status: what the ticket becomes after a public reply (awaiting | resolved | open). */
    public function staffReply(SupportTicket $ticket, User $staff, string $body, bool $internal, string $status = SupportTicket::AWAITING, array $files = []): SupportMessage
    {
        $message = DB::transaction(function () use ($ticket, $staff, $body, $internal, $status, $files) {
            $message = $this->addMessage($ticket, $staff, $body, true, $internal, $files);
            if (! $internal) {
                $ticket->update([
                    'status' => $status,
                    'last_reply_at' => now(),
                    'resolved_at' => $status === SupportTicket::RESOLVED ? now() : null,
                    'assigned_to' => $ticket->assigned_to ?? $staff->id,
                ]);
            }

            return $message;
        });

        if (! $internal) {
            $this->mailCustomer($ticket, $message);
        }

        return $message;
    }

    public function setStatus(SupportTicket $ticket, string $status): SupportTicket
    {
        $ticket->update(['status' => $status, 'resolved_at' => $status === SupportTicket::RESOLVED ? now() : null]);

        return $ticket;
    }

    /** A license of the customer's own organisation, by its public uuid. */
    public static function licenseFor(User $customer, ?string $uuid): ?License
    {
        return $uuid ? License::query()->where('organisation_id', $customer->organisation_id)->where('uuid', $uuid)->first() : null;
    }

    public static function fileRules(): array
    {
        return [
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB, 'mimes:'.self::FILE_TYPES],
        ];
    }

    /** @param  UploadedFile[]  $files */
    private function addMessage(SupportTicket $ticket, User $author, string $body, bool $staff, bool $internal, array $files): SupportMessage
    {
        $stored = [];
        foreach (array_values(array_slice($files, 0, self::MAX_FILES)) as $i => $file) {
            $name = Str::limit((string) preg_replace('/[^\w.\- ]+/u', '_', $file->getClientOriginalName()) ?: 'file', 120, '');
            $stored[] = [
                'id' => $i,
                'name' => $name,
                'path' => $file->storeAs("support/{$ticket->id}", Str::random(24).'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local'),
                'size' => $file->getSize(),
            ];
        }

        return $ticket->messages()->create([
            'user_id' => $author->id,
            'author_name' => $author->name,
            'from_staff' => $staff,
            'internal' => $internal,
            'body' => $body,
            'attachments' => $stored ?: null,
            'created_at' => now(),
        ]);
    }

    private function mailStaff(SupportTicket $ticket, SupportMessage $message, bool $new): void
    {
        $to = $ticket->assignee?->email ? [$ticket->assignee->email] : User::staffEmails('support');
        $this->send($to, new SupportTicketMail($ticket, $message, toStaff: true, isNew: $new));
    }

    private function mailCustomer(SupportTicket $ticket, SupportMessage $message): void
    {
        $to = collect([$ticket->user?->email, $ticket->organisation?->email])->filter()->unique()->values()->all();
        $this->send($to, new SupportTicketMail($ticket, $message, toStaff: false, isNew: false));
    }

    private function send(array $to, SupportTicketMail $mail): void
    {
        if (! $to) {
            return;
        }
        try {
            Mail::to(array_shift($to))->cc($to)->send($mail);
        } catch (Throwable $e) {
            Log::warning('Support email failed', ['ticket' => $mail->ticket->number, 'error' => $e->getMessage()]);
        }
    }
}
