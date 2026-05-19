<?php

namespace App\Http\Controllers\Admin\SupportTicket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportTicket\AdminStoreSupportTicketMessageRequest;
use App\Http\Requests\Admin\SupportTicket\AdminUpdateSupportTicketRequest;
use App\Jobs\SendAdminTicketMessageNotificationJob;
use App\Jobs\SendClientTicketReplyNotificationJob;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::with([
            'user:id,first_name,last_name,email,phone,job_title,organization_id',
            'user.organization:id,name',
        ])->withCount('messages');

        if ($request->filled('status') && in_array($request->status, SupportTicket::STATUSES)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority') && in_array($request->priority, SupportTicket::PRIORITIES)) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('ticket_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $sort_by  = in_array($request->get('sort_by', 'created_at'), ['created_at', 'updated_at', 'status', 'priority']) ? $request->get('sort_by', 'created_at') : 'created_at';
        $sort_dir = $request->get('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $tickets = $query->orderBy($sort_by, $sort_dir)
            ->paginate($request->get('per_page', 20));

        return response()->json($tickets);
    }

    public function show(SupportTicket $support_ticket): JsonResponse
    {
        $support_ticket->load([
            'user:id,first_name,last_name,email,phone,job_title,organization_id',
            'user.organization:id,name',
            'messages.sender:id,first_name,last_name,email',
        ]);

        $client = $support_ticket->user;
        $client_stats = null;

        if ($client) {
            $client_stats = [
                'total_tickets' => SupportTicket::where('user_id', $client->id)->count(),
                'open_tickets'  => SupportTicket::where('user_id', $client->id)->whereIn('status', ['open', 'in_progress'])->count(),
                'member_since'  => $client->created_at?->toDateString(),
            ];
        }

        return response()->json([
            'support_ticket' => $support_ticket,
            'client_stats'   => $client_stats,
        ]);
    }

    public function update(AdminUpdateSupportTicketRequest $request, SupportTicket $support_ticket): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['status'])) {
            if ($data['status'] === 'resolved' && $support_ticket->status !== 'resolved') {
                $data['resolved_at'] = now();
            }

            if ($data['status'] === 'closed' && $support_ticket->status !== 'closed') {
                $data['closed_at'] = now();
            }

            if (in_array($data['status'], ['open', 'in_progress']) && in_array($support_ticket->status, ['resolved', 'closed'])) {
                $data['resolved_at'] = null;
                $data['closed_at']   = null;
            }
        }

        $support_ticket->update($data);

        $support_ticket->load([
            'user:id,first_name,last_name,email,phone,job_title,organization_id',
            'user.organization:id,name',
            'messages.sender:id,first_name,last_name,email',
        ]);

        return response()->json([
            'message'         => 'Ticket updated successfully.',
            'support_ticket'  => $support_ticket,
        ]);
    }

    public function storeMessage(AdminStoreSupportTicketMessageRequest $request, SupportTicket $support_ticket): JsonResponse
    {
        if ($support_ticket->status === 'closed') {
            return response()->json(['message' => 'Cannot add messages to a closed ticket.'], 422);
        }

        $admin = auth()->user();

        $ticket_message = $support_ticket->messages()->create([
            'sender_id' => $admin->id,
            'content'   => $request->content,
        ]);

        $ticket_message->load('sender:id,first_name,last_name,email');

        $client = $support_ticket->user ?? User::find($support_ticket->user_id);

        if ($client) {
            $view_ticket_url = rtrim(config('app.frontend_url'), '/') . "/support/{$support_ticket->id}";
            $admin_name      = trim("{$admin->first_name} {$admin->last_name}");
            $admin_initials  = strtoupper(substr($admin->first_name, 0, 1) . substr($admin->last_name, 0, 1));

            SendClientTicketReplyNotificationJob::dispatch(
                ticket_number:    $support_ticket->ticket_number,
                ticket_subject:   $support_ticket->subject,
                ticket_id:        (string) $support_ticket->id,
                client_name:      trim("{$client->first_name} {$client->last_name}"),
                client_email:     $client->email,
                admin_name:       $admin_name,
                admin_initials:   $admin_initials,
                reply_content:    $request->content,
                reply_date:       $ticket_message->created_at->format('M d, Y \a\t g:i A'),
                view_ticket_url:  $view_ticket_url,
            );
        }

        if ($support_ticket->status === 'open') {
            $support_ticket->update(['status' => 'in_progress']);
            $support_ticket->refresh();
        }

        return response()->json([
            'message'        => 'Reply sent successfully.',
            'ticket_message' => $ticket_message,
            'support_ticket' => $support_ticket->fresh(),
        ], 201);
    }

    public function stats(): JsonResponse
    {
        $counts = SupportTicket::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $all_statuses = array_fill_keys(SupportTicket::STATUSES, 0);
        $counts       = array_merge($all_statuses, $counts);

        return response()->json([
            'total'       => array_sum($counts),
            'open'        => $counts['open'] ?? 0,
            'in_progress' => $counts['in_progress'] ?? 0,
            'resolved'    => $counts['resolved'] ?? 0,
            'closed'      => $counts['closed'] ?? 0,
        ]);
    }
}
