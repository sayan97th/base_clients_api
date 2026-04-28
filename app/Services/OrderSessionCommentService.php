<?php

namespace App\Services;

use App\Models\OrderSessionComment;
use App\Models\Role;
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

    public function isAdminOrStaff(User $user): bool
    {
        return $user->hasRole(self::ADMIN_ROLES);
    }

    public function formatComment(OrderSessionComment $comment, bool $with_replies = false): array
    {
        $user = $comment->relationLoaded('user') ? $comment->user : null;

        return [
            'id'                => $comment->id,
            'session_id'        => $comment->session_id,
            'user_id'           => $comment->user_id,
            'parent_id'         => $comment->parent_id,
            'content'           => $comment->content,
            'is_admin_comment'  => $user ? $user->hasRole(self::ADMIN_ROLES) : false,
            'author_name'       => $user ? trim($user->first_name . ' ' . $user->last_name) : null,
            'author_avatar_url' => $user?->profile_photo_url,
            'created_at'        => $comment->created_at,
            'updated_at'        => $comment->updated_at,
            'replies'           => $with_replies
                ? $comment->replies->map(fn ($r) => $this->formatComment($r))->values()->all()
                : [],
        ];
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
