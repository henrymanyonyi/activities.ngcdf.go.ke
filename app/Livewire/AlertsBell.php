<?php

namespace App\Livewire;

use App\Notifications\ActivityAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * FRD NT-01: in-app alerts for the signed-in user. The bell checks every few
 * seconds (wire:poll) and shows a toast for each alert that arrives while the
 * page is open, so the user sees it without reloading.
 */
class AlertsBell extends Component
{
    public bool $open = false;

    /** Alerts created after this moment are new to this page. */
    public string $since = '';

    public function mount(): void
    {
        $this->since = now()->toIso8601String();
    }

    /** Called by the poll: toasts the alerts that arrived since the last check. */
    public function checkForNew(): void
    {
        $since = Carbon::parse($this->since);
        $this->since = now()->toIso8601String();

        $new = Auth::user()->unreadNotifications()->where('created_at', '>', $since)->latest()->limit(5)->get();
        if ($new->isEmpty()) {
            return;
        }

        $latest = (string) ($new->first()->data['message'] ?? 'You have a new alert.');
        $this->dispatch('notify', message: $new->count() === 1 ? $latest : "{$new->count()} new alerts. Latest: {$latest}", type: 'info');
    }

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
