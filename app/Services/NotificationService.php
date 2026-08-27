<?php

namespace App\Services;

use App\Events\NewNotification;
use App\Jobs\SendEmailJob;
use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    /**
     * Return all notifications for a user as a flat collection, including archived ones.
     * Used by the frontend for client-side tab separation (Active vs Archived).
     */
    public function getAllNotifications(User $user, array $filters = []): Collection
    {
        $query = Notification::forUser($user->id)
            ->notSnoozed();

        $this->applyFilters($query, $filters);

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Return paginated notifications including archived — used when the caller passes a per_page param.
     */
    public function getNotifications(User $user, array $filters = [], int $per_page = 15): LengthAwarePaginator
    {
        $query = Notification::forUser($user->id)
            ->notSnoozed();

        $this->applyFilters($query, $filters);

        return $query->orderByDesc('created_at')->paginate($per_page);
    }

    public function getUnreadCount(User $user): int
    {
        return Notification::forUser($user->id)
            ->unread()
            ->notArchived()
            ->notSnoozed()
            ->count();
    }

    public function createNotification(User $user, string $type, string $message, array $extra = []): Notification
    {
        $notification = Notification::create([
            'user_id'      => $user->id,
            'type'         => $type,
            'message'      => $message,
            'preview_text' => $extra['preview_text'] ?? null,
            'link'         => $extra['link'] ?? null,
            'resource_type' => $extra['resource_type'] ?? null,
            'resource_id'   => $extra['resource_id'] ?? null,
            'metadata'      => $extra['metadata'] ?? null,
        ]);

        $this->sendEmailIfEnabled($user, $notification, $extra['mail_data'] ?? []);

        broadcast(new NewNotification($notification));

        return $notification;
    }

    public function markAsRead(Notification $notification): void
    {
        $notification->markAsRead();
    }

    public function markAllAsRead(User $user): int
    {
        return Notification::forUser($user->id)
            ->unread()
            ->notArchived()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function archive(Notification $notification): void
    {
        $notification->archive();
    }

    public function unarchive(Notification $notification): void
    {
        $notification->unarchive();
    }

    public function snooze(Notification $notification, \DateTimeInterface $until): void
    {
        $notification->update([
            'is_snoozed'   => true,
            'is_read'      => true,
            'read_at'      => now(),
            'snoozed_until' => $until,
        ]);
    }

    /**
     * Return paginated platform notifications for admin — across all users, including archived.
     * Frontend separates Active and Archived tabs using is_archived.
     */
    public function getAdminNotifications(array $filters = [], int $per_page = 15): LengthAwarePaginator
    {
        $query = Notification::with('user:id,first_name,last_name,email')
            ->orderByDesc('created_at');

        $this->applyFilters($query, $filters);

        return $query->paginate($per_page);
    }

    /**
     * Return count of all unread, non-archived platform notifications.
     */
    public function getAdminUnreadCount(): int
    {
        return Notification::unread()
            ->notArchived()
            ->count();
    }

    /**
     * Mark all non-archived platform notifications as read.
     */
    public function markAdminAllAsRead(): int
    {
        return Notification::unread()
            ->notArchived()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function unsnoozeExpired(): int
    {
        return Notification::where('is_snoozed', true)
            ->where('snoozed_until', '<=', now())
            ->update([
                'is_snoozed'    => false,
                'snoozed_until' => null,
            ]);
    }

    public function getOrCreatePreferences(User $user): NotificationPreference
    {
        return $user->notificationPreference ?? $user->notificationPreference()->create();
    }

    public function updatePreferences(User $user, array $data): NotificationPreference
    {
        $preference = $this->getOrCreatePreferences($user);
        $preference->update($data);

        return $preference->fresh();
    }

    /**
     * Types that share a filter bucket with a broader category. "order_comment" notifications
     * are a sub-kind of "order" activity, so the "Orders" filter tab in the portal should also
     * surface them instead of only exact-matching the literal "order" type value.
     */
    protected const TYPE_FILTER_GROUPS = [
        'order' => ['order', 'order_comment'],
    ];

    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['type'])) {
            $grouped_types = self::TYPE_FILTER_GROUPS[$filters['type']] ?? [$filters['type']];
            $query->whereIn('type', $grouped_types);
        }

        if (isset($filters['is_read'])) {
            $filters['is_read'] ? $query->where('is_read', true) : $query->unread();
        }
    }

    protected function sendEmailIfEnabled(User $user, Notification $notification, array $mail_data = []): void
    {
        if ($mail_data['skip_email'] ?? false) {
            return;
        }

        $preference = $user->notificationPreference;

        if (!$preference || $preference->shouldSendEmail()) {
            SendEmailJob::dispatch(new NotificationEmail($user, $notification, $mail_data), $user->email);
        }
    }


}
