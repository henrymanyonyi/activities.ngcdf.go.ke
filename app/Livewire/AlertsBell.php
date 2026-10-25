<?php

namespace App\Livewire;

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

        $user = Auth::user();
        $activityId = $alert->data['activity_id'] ?? null;
        $url = $alert->data['url'] ?? null;

        $this->redirect(match (true) {
            $activityId && $user->can('activities.view') => route('activities.show', $activityId),
            $activityId && $user->can('memos.originate') => route('memos.show', $activityId),
            $url && $user->canAny(['budgets.prepare', 'budgets.approve', 'instructions.action']) => $url,
            default => route('home'),
        }, navigate: true);
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
