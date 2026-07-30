<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\OperationalAlert;
use Illuminate\Notifications\DatabaseNotification;

class NotificationSyncService
{
    public function __construct(
        private readonly OperationalIssueService $issues
    ) {
    }

    public function syncForAllUsers(): array
    {
        $alerts = $this->issues->notificationAlerts();
        $users = User::query()->get();

        $created = 0;
        $resolved = 0;

        foreach ($users as $user) {
            [$userCreated, $userResolved] = $this->syncForUser($user, $alerts);
            $created += $userCreated;
            $resolved += $userResolved;
        }

        return compact('created', 'resolved');
    }

    public function syncForUser(User $user, ?array $alerts = null): array
    {
        $alerts ??= $this->issues->notificationAlerts();
        $activeKeys = collect($alerts)->pluck('key')->all();

        $existing = $user->notifications()
            ->where('type', OperationalAlert::class)
            ->get();

        $existingByKey = $existing->keyBy(
            fn (DatabaseNotification $notification) => data_get($notification->data, 'key')
        );

        $created = 0;

        foreach ($alerts as $alert) {
            if (!$existingByKey->has($alert['key'])) {
                $user->notify(new OperationalAlert($alert));
                $created++;
                continue;
            }

            $notification = $existingByKey->get($alert['key']);

            if ($notification->data !== $alert) {
                $notification->forceFill(['data' => $alert])->save();
            }
        }

        $resolvedNotifications = $existing->filter(function (DatabaseNotification $notification) use ($activeKeys) {
            $key = data_get($notification->data, 'key');

            return $key && !in_array($key, $activeKeys, true);
        });

        $resolved = $resolvedNotifications->count();

        foreach ($resolvedNotifications as $notification) {
            $notification->delete();
        }

        return [$created, $resolved];
    }
}
