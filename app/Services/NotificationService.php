<?php

namespace App\Services;

use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function getNotifications(User $user, array $filters = [], int $per_page = 15): LengthAwarePaginator
    {
        $query = Notification::forUser($user->id)
            ->notArchived()
            ->notSnoozed();

        if (isset($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (isset($filters['is_read'])) {
            $filters['is_read'] ? $query->where('is_read', true) : $query->unread();
        }

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
            'user_id' => $user->id,
            'type' => $type,
            'message' => $message,
            'preview_text' => $extra['preview_text'] ?? null,
            'link' => $extra['link'] ?? null,
        ]);

        $this->sendEmailIfEnabled($user, $notification);

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

    public function snooze(Notification $notification, \DateTimeInterface $until): void
    {
        $notification->snooze($until);
    }

    public function unsnoozeExpired(): int
    {
        return Notification::where('is_snoozed', true)
            ->where('snoozed_until', '<=', now())
            ->update([
                'is_snoozed' => false,
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

    protected function sendEmailIfEnabled(User $user, Notification $notification): void
    {
        $preference = $user->notificationPreference;

        if (!$preference || $preference->shouldSendEmail()) {
            Mail::to($user->email)->queue(new NotificationEmail($user, $notification));
        }
    }
}
