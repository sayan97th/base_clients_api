<?php

namespace App\Services;

use App\Models\OrderSessionComment;
use App\Models\User;
use App\Support\FrontendUrl;
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
                ->first(['user_id', 'session_id', 'order_title']);

            if ($order) {
                return $order;
            }
        }

        return null;
    }

    public function findSessionDetails(string $session_id): ?object
    {
        foreach (self::ORDER_TABLES as $table) {
            $order = DB::table($table)
                ->where('session_id', $session_id)
                ->first(['id', 'user_id', 'session_id', 'order_title']);

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

    /**
     * Builds the portal-relative path to the exact comment on an order, so a click (from the
     * notification bell or an email button) lands on the comment itself instead of just the
     * order's root page. The order route is singular ("session", not "sessions") to match the
     * actual Next.js file-system routes.
     */
    public function buildCommentPath(bool $is_admin, ?string $order_id, ?string $session_id, int $comment_id): string
    {
        $prefix = $is_admin ? '/admin' : '';
        $base_path = $order_id
            ? "{$prefix}/orders/{$order_id}"
            : "{$prefix}/orders/session/{$session_id}";

        return "{$base_path}?comment_id={$comment_id}#comment-{$comment_id}";
    }

    /**
     * Same as buildCommentPath(), but returns an absolute URL for use in email CTAs.
     */
    public function buildCommentUrl(bool $is_admin, ?string $order_id, ?string $session_id, int $comment_id): string
    {
        return FrontendUrl::to($this->buildCommentPath($is_admin, $order_id, $session_id, $comment_id));
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
        $is_order_based = $comment->session_id === null && $comment->order_id !== null;

        if ($this->isAdminOrStaff($author)) {
            $owner_user_id = $is_order_based
                ? $this->findOrderOwnerUserId($comment->order_id)
                : $this->findSessionOwnerUserId($comment->session_id);

            if ($owner_user_id && $owner_user_id !== $author->id) {
                $client = User::find($owner_user_id);

                if ($client) {
                    $client_link = $this->buildCommentPath(
                        false,
                        $comment->order_id,
                        $comment->session_id,
                        $comment->id
                    );

                    $notification_service->createNotification(
                        $client,
                        'order_comment',
                        'A staff member replied to your order discussion.',
                        [
                            'link'          => $client_link,
                            'resource_type' => 'order_comment',
                            'resource_id'   => (string) $comment->id,
                            'metadata'      => [
                                'comment_id'    => $comment->id,
                                'parent_id'     => $comment->parent_id,
                                'order_id'      => $comment->order_id,
                                'session_id'    => $comment->session_id,
                                'purchase_type' => $is_order_based ? 'single_order' : 'multi_purchase',
                                'author_id'     => $author->id,
                                'author_name'   => "{$author->first_name} {$author->last_name}",
                            ],
                            'mail_data' => ['skip_email' => true],
                        ]
                    );
                }
            }
        } else {
            /** @var \Illuminate\Database\Eloquent\Collection<int, User> $admins */
            $admins = User::whereHas(
                'roles',
                fn ($q) => $q->whereIn('name', self::ADMIN_ROLES)
            )->get();

            $admin_link = $this->buildCommentPath(
                true,
                $comment->order_id,
                $comment->session_id,
                $comment->id
            );

            foreach ($admins as $admin) {
                if ($admin->id !== $author->id) {
                    $notification_service->createNotification(
                        $admin,
                        'order_comment',
                        "{$author->first_name} {$author->last_name} posted a comment on an order.",
                        [
                            'link'          => $admin_link,
                            'resource_type' => 'order_comment',
                            'resource_id'   => (string) $comment->id,
                            'metadata'      => [
                                'comment_id'    => $comment->id,
                                'parent_id'     => $comment->parent_id,
                                'order_id'      => $comment->order_id,
                                'session_id'    => $comment->session_id,
                                'purchase_type' => $is_order_based ? 'single_order' : 'multi_purchase',
                                'author_id'     => $author->id,
                                'author_name'   => "{$author->first_name} {$author->last_name}",
                            ],
                            'mail_data' => ['skip_email' => true],
                        ]
                    );
                }
            }
        }
    }
}
