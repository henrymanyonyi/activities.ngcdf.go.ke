<?php

namespace App\Livewire;

use App\Notifications\ActivityAlert;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/** FRD NT-01: in-app alerts for the signed-in user. */
class AlertsBell extends Component
{
    public bool $open = false;

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function openAlert(string $id): void
    {
        $alert = Auth::user()->notifications()->findOrFail($id);
        $alert->markAsRead();

        $this->redirect(ActivityAlert::destination($alert->data, Auth::user()), navigate: true);
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.alerts-bell', [
            'unread' => $user->unreadNotifications()->count(),
            'alerts' => $this->open ? $user->notifications()->latest()->limit(15)->get() : collect(),
        ]);
    }
}
