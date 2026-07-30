<?php

namespace App\Http\Controllers;

use App\Services\NotificationSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationSyncService $service): View
    {
        $service->syncForUser($request->user());

        $query = $request->user()
            ->notifications()
            ->latest();

        if ($request->filled('status')) {
            $request->string('status')->toString() === 'unread'
                ? $query->whereNull('read_at')
                : $query->whereNotNull('read_at');
        }

        if ($request->filled('category')) {
            $query->where('data->category', $request->string('category')->toString());
        }

        if ($request->filled('q')) {
            $keyword = trim($request->string('q')->toString());

            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('data->title', 'like', "%{$keyword}%")
                    ->orWhere('data->message', 'like', "%{$keyword}%")
                    ->orWhere('data->asset_code', 'like', "%{$keyword}%")
                    ->orWhere('data->driver_name', 'like', "%{$keyword}%");
            });
        }

        $notifications = $query->paginate(20)->withQueryString();
        $unreadCount = $request->user()->unreadNotifications()->count();

        return view('notifications.index', compact('notifications', 'unreadCount'));
    }

    public function read(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless(
            $notification->notifiable_type === $request->user()::class
            && (string) $notification->notifiable_id === (string) $request->user()->getKey(),
            403
        );

        $notification->markAsRead();

        $url = data_get($notification->data, 'url');

        return $url ? redirect()->to($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Đã đánh dấu tất cả thông báo là đã đọc.');
    }

    public function destroy(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless(
            $notification->notifiable_type === $request->user()::class
            && (string) $notification->notifiable_id === (string) $request->user()->getKey(),
            403
        );

        $notification->delete();

        return back()->with('success', 'Đã xóa thông báo.');
    }

    public function unreadCount(Request $request, NotificationSyncService $service)
    {
        $service->syncForUser($request->user());

        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }
}
