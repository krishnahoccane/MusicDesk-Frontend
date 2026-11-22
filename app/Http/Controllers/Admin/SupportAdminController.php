<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupportController;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Site admin: answer customer support tickets. */
class SupportAdminController extends Controller
{
    public function __construct(private SupportDesk $desk) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', 'open');
        $tickets = SupportTicket::query()->with(['organisation', 'license.plan', 'assignee'])->withCount('publicMessages')
            ->search($request->query('q'))
            ->when(in_array($status, [SupportTicket::OPEN, SupportTicket::AWAITING, SupportTicket::RESOLVED], true), fn ($q) => $q->where('status', $status))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->query('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
            ->when($status === SupportTicket::OPEN, fn ($q) => $q->oldest('last_reply_at'), fn ($q) => $q->latest('last_reply_at'))
            ->paginate(25)->withQueryString();

        return view('admin.support.index', [
            'tickets' => $tickets,
            'status' => $status,
            'counts' => SupportTicket::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        $ticket->load(['organisation.users', 'license.plan', 'license.activations', 'user', 'assignee', 'messages']);

        return view('admin.support.show', [
            'ticket' => $ticket,
            'staff' => User::query()->where('is_admin', true)->orderBy('name')->get(['id', 'name']),
            'history' => SupportTicket::query()->where('organisation_id', $ticket->organisation_id)->whereKeyNot($ticket->id)->latest('id')->limit(5)->get(),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'internal' => ['nullable', 'boolean'],
            'then' => ['nullable', Rule::in([SupportTicket::AWAITING, SupportTicket::RESOLVED, SupportTicket::OPEN])],
            ...SupportDesk::fileRules(),
        ]);
        $internal = $request->boolean('internal');
        $this->desk->staffReply($ticket, $request->user(), $data['body'], $internal, $data['then'] ?? SupportTicket::AWAITING, $request->file('files', []));

        return redirect()->route('admin.support.show', $ticket)->with('toast', $internal ? 'Internal note added' : 'Reply sent to '.$ticket->organisation->name);
    }

    public function update(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([SupportTicket::OPEN, SupportTicket::AWAITING, SupportTicket::RESOLVED])],
            'priority' => ['required', Rule::in(array_keys(SupportTicket::PRIORITIES))],
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('is_admin', true)],
        ]);
        if ($data['status'] !== $ticket->status) {
            $this->desk->setStatus($ticket, $data['status']);
        }
        $ticket->update(['priority' => $data['priority'], 'category' => $data['category'], 'assigned_to' => $data['assigned_to'] ?? null]);

        return back()->with('toast', "{$ticket->number} updated");
    }

    public function attachment(SupportTicket $ticket, SupportMessage $message, int $file): StreamedResponse
    {
        abort_if($message->support_ticket_id !== $ticket->id, 404);

        return SupportController::download($message, $file);
    }
}
