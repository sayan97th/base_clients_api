<?php

namespace App\Services;

use App\Models\OrderSessionComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderSessionCommentService
{
    private const ORDER_TABLES = [
        'link_building_orders',
        'new_content_orders',
        'content_optimization_orders',
        'content_brief_orders',
    ];

    private const ADMIN_ROLES = ['super_admin', 'admin', 'staff'];

    public function sessionExists(string $session_id): bool
    {
        return $this->findSessionOwnerUserId($session_id) !== null;
    }

    public function findSessionOwnerUserId(string $session_id): ?int
    {
        foreach (self::ORDER_TABLES as $table) {
            $order = DB::table($table)
                ->where('session_id', $session_id)
                ->first(['user_id']);

            if ($order) {
                return (int) $order->user_id;
            }
        }

        return null;
    }

    public function findOrderOwnerUserId(string $order_id): ?int
    {
        $order = $this->findOrderDetails($order_id);

        return $order ? (int) $order->user_id : null;
    }

    public function findOrderDetails(string $order_id): ?object
    {
        foreach (self::ORDER_TABLES as $table) {
            $order = DB::table($table)
                ->where('id', $order_id)
                ->first(['user_id', 'session_id']);

            if ($order) {
                return $order;
            }
        }

        return null;
    }

    public function isAdminOrStaff(User $user): bool
    {
        return $user->hasRole(self::ADMIN_ROLES);
    }

    public function formatComment(OrderSessionComment $comment, bool $with_replies = false): array
    {
        return [
            'id'                => $comment->id,
            'session_id'        => $comment->session_id,
            'user_id'           => $comment->user_id,
            'parent_id'         => $comment->parent_id,
            'content'           => $comment->content,
            'is_admin_comment'  => (bool) $comment->is_admin_comment,
            'author_name'       => $comment->author_name,
            'author_avatar_url' => $comment->author_avatar_url,
            'created_at'        => $comment->created_at,
            'updated_at'        => $comment->updated_at,
            'replies'           => $with_replies
                ? $this->formatReplies($comment->replies)
                : [],
        ];
    }

    private function formatReplies($replies): array
    {
        return $replies->map(function (OrderSessionComment $reply) {
            return [
                'id'                => $reply->id,
                'session_id'        => $reply->session_id,
                'user_id'           => $reply->user_id,
                'parent_id'         => $reply->parent_id,
                'content'           => $reply->content,
                'is_admin_comment'  => (bool) $reply->is_admin_comment,
                'author_name'       => $reply->author_name,
                'author_avatar_url' => $reply->author_avatar_url,
                'created_at'        => $reply->created_at,
                'updated_at'        => $reply->updated_at,
                'replies'           => $this->formatReplies($reply->replies),
            ];
        })->values()->all();
    }

    public function notifyOnNewComment(
        OrderSessionComment $comment,
        User $author,
        NotificationService $notification_service
    ): void {
        if ($this->isAdminOrStaff($author)) {
            $owner_user_id = $this->findSessionOwnerUserId($comment->session_id);

            if ($owner_user_id && $owner_user_id !== $author->id) {
                $client = User::find($owner_user_id);

                if ($client) {
                    $notification_service->createNotification(
                        $client,
                        'order_comment',
                        'A staff member replied to your order discussion.',
                        ['link' => "/orders/sessions/{$comment->session_id}"]
                    );
                }
            }
        } else {
            $admins = User::whereHas(
                'roles',
                fn ($q) => $q->whereIn('name', self::ADMIN_ROLES)
            )->get();

            foreach ($admins as $admin) {
                if ($admin->id !== $author->id) {
                    $notification_service->createNotification(
                        $admin,
                        'order_comment',
                        "{$author->first_name} {$author->last_name} posted a comment on an order.",
                        ['link' => "/admin/orders/sessions/{$comment->session_id}"]
                    );
                }
            }
        }
    }
}
