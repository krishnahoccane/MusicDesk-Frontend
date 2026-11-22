<?php

namespace App\Http\Controllers;

use App\Licensing\LicensePresenter;
use App\Models\Organisation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Support\SupportDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Customer portal: support tickets for the signed-in user's organisation. */
class SupportController extends Controller
{
    public function __construct(private SupportDesk $desk) {}

    public function index(Request $request): View
    {
        $orgId = $request->user()->organisation_id;
        $tickets = SupportTicket::query()->where('organisation_id', $orgId)->with('license')
            ->withCount('publicMessages')->orderByRaw("case status when 'resolved' then 1 else 0 end")->latest('last_reply_at')->get();

        $active = $request->query('t') ? $tickets->firstWhere('number', $request->query('t')) : null;
        $active?->load(['publicMessages']);

        return view('dashboard.support', [
            'tickets' => $tickets,
            'active' => $active,
            'licenses' => LicensePresenter::forOrganisation(Organisation::find($orgId)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->organisation_id, 403);
        $data = $request->validate([
            'license' => ['nullable', 'string', 'max:40'],
            'domain' => ['nullable', 'string', 'max:253'],
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'priority' => ['required', Rule::in(array_keys(SupportTicket::PRIORITIES))],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            ...SupportDesk::fileRules(),
        ]);

        $license = SupportDesk::licenseFor($request->user(), $data['license'] ?? null);
        $ticket = $this->desk->open($request->user(), [...$data, 'license_id' => $license?->id], $request->file('files', []));

        return redirect()->route('dashboard.support', ['t' => $ticket->number])->with('toast', "Ticket {$ticket->number} created. We’ll reply here and by email.");
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], ...SupportDesk::fileRules()]);
        $this->desk->customerReply($ticket, $request->user(), $data['body'], $request->file('files', []));

        return redirect()->route('dashboard.support', ['t' => $ticket->number])->with('toast', 'Reply sent');
    }

    public function status(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['status' => ['required', Rule::in([SupportTicket::RESOLVED, SupportTicket::OPEN])]]);
        $this->desk->setStatus($ticket, $data['status']);

        return redirect()->route('dashboard.support', ['t' => $ticket->number])
            ->with('toast', $data['status'] === SupportTicket::RESOLVED ? 'Ticket marked as resolved' : 'Ticket reopened');
    }

    public function attachment(Request $request, SupportTicket $ticket, SupportMessage $message, int $file): StreamedResponse
    {
        $this->authorizeTicket($request, $ticket);
        abort_if($message->support_ticket_id !== $ticket->id || $message->internal, 404);

        return self::download($message, $file);
    }

    public static function download(SupportMessage $message, int $file): StreamedResponse
    {
        $att = collect($message->attachments ?? [])->firstWhere('id', $file);
        abort_unless($att && Storage::disk('local')->exists($att['path']), 404);

        return Storage::disk('local')->download($att['path'], $att['name']);
    }

    private function authorizeTicket(Request $request, SupportTicket $ticket): void
    {
        abort_unless($ticket->organisation_id && $ticket->organisation_id === $request->user()->organisation_id, 404);
    }
}
