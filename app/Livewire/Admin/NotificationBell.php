<?php

namespace App\Livewire\Admin;

use Livewire\Component;

class NotificationBell extends Component
{
    public function markAsRead(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->whereKey($notificationId)->first();
        $notification?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.admin.notification-bell', [
            'notifications' => $user->notifications()->latest()->take(10)->get(),
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }
}
