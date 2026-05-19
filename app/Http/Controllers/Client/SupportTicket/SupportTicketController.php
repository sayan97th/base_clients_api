<?php

namespace App\Http\Controllers\Client\SupportTicket;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportTicket\StoreSupportTicketMessageRequest;
use App\Http\Requests\SupportTicket\StoreSupportTicketRequest;
use App\Http\Requests\SupportTicket\UpdateSupportTicketRequest;
use App\Jobs\SendAdminNewTicketNotificationJob;
use App\Jobs\SendAdminTicketMessageNotificationJob;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = SupportTicket::where('user_id', $user->id);

        if ($request->has('status') && in_array($request->status, SupportTicket::STATUSES)) {
            $query->where('status', $request->status);
        }

        if ($request->has('priority') && in_array($request->priority, SupportTicket::PRIORITIES)) {
            $query->where('priority', $request->priority);
        }

        $support_tickets = $query->with('user:id,first_name,last_name,email')
            ->withCount('messages')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($support_tickets);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $user = auth()->user();

        $support_ticket = DB::transaction(function () use ($request, $user) {
            $ticket = SupportTicket::create([
                'subject' => $request->subject,
                'priority' => $request->priority ?? 'medium',
                'related_order' => $request->related_order,
                'user_id' => $user->id,
            ]);

            $ticket->messages()->create([
                'sender_id' => $user->id,
                'content' => $request->content,
            ]);

            return $ticket->load('messages.sender:id,first_name,last_name,email');
        });

        $client_name     = trim("{$user->first_name} {$user->last_name}");
        $client_initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));
        $view_ticket_url = rtrim(config('app.admin_url', config('app.url')), '/') . "/admin/support-tickets/{$support_ticket->id}";
        $settings_url    = rtrim(config('app.admin_url', config('app.url')), '/') . '/admin/settings/email-notifications';

        SendAdminNewTicketNotificationJob::dispatch(
            ticket_number:   $support_ticket->ticket_number,
            ticket_subject:  $support_ticket->subject,
            ticket_priority: $support_ticket->priority,
            client_name:     $client_name,
            client_email:    $user->email,
            client_initials: $client_initials,
            initial_message: $request->content,
            ticket_date:     $support_ticket->created_at->format('M d, Y \a\t g:i A'),
            view_ticket_url: $view_ticket_url,
            settings_url:    $settings_url,
        );

        return response()->json([
            'message' => 'Support ticket created successfully.',
            'support_ticket' => $support_ticket,
        ], 201);
    }

    public function show(SupportTicket $support_ticket): JsonResponse
    {
        $user = auth()->user();

        if ($support_ticket->user_id !== $user->id && !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $support_ticket->load([
            'user:id,first_name,last_name,email',
            'messages.sender:id,first_name,last_name,email',
        ]);

        return response()->json([
            'support_ticket' => $support_ticket,
        ]);
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $support_ticket): JsonResponse
    {
        $user = auth()->user();

        if ($support_ticket->user_id !== $user->id && !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();

        if (isset($data['status'])) {
            if ($data['status'] === 'resolved' && $support_ticket->status !== 'resolved') {
                $data['resolved_at'] = now();
            }

            if ($data['status'] === 'closed' && $support_ticket->status !== 'closed') {
                $data['closed_at'] = now();
            }
        }

        $support_ticket->update($data);

        return response()->json([
            'message' => 'Support ticket updated successfully.',
            'support_ticket' => $support_ticket->fresh()->load('user:id,first_name,last_name,email'),
        ]);
    }

    public function storeMessage(StoreSupportTicketMessageRequest $request, SupportTicket $support_ticket): JsonResponse
    {
        $user = auth()->user();

        if ($support_ticket->user_id !== $user->id && !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (in_array($support_ticket->status, ['closed'])) {
            return response()->json(['message' => 'Cannot add messages to a closed ticket.'], 422);
        }

        $message = $support_ticket->messages()->create([
            'sender_id' => $user->id,
            'content' => $request->content,
        ]);

        $message->load('sender:id,first_name,last_name,email');

        $client_name     = trim("{$user->first_name} {$user->last_name}");
        $client_initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));
        $view_ticket_url = rtrim(config('app.admin_url', config('app.url')), '/') . "/admin/support-tickets/{$support_ticket->id}";
        $settings_url    = rtrim(config('app.admin_url', config('app.url')), '/') . '/admin/settings/email-notifications';

        SendAdminTicketMessageNotificationJob::dispatch(
            ticket_number:   $support_ticket->ticket_number,
            ticket_subject:  $support_ticket->subject,
            client_name:     $client_name,
            client_email:    $user->email,
            client_initials: $client_initials,
            message_content: $request->content,
            message_date:    $message->created_at->format('M d, Y \a\t g:i A'),
            view_ticket_url: $view_ticket_url,
            settings_url:    $settings_url,
        );

        return response()->json([
            'message' => 'Message added successfully.',
            'ticket_message' => $message,
        ], 201);
    }
}