<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Models\ActivityCategory;
use App\Models\Department;
use App\Models\SubmissionLink;
use App\Services\SubmissionLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Magic links a memo originator uses to send an activity in. Submissions
 * arrive as Drafts for the Chief of Staff to review (see ActivitySubmission).
 */
#[Layout('layouts.admin')]
#[Title('Submission links')]
class Links extends Component
{
    use RunsActions;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $label = '';

    public string $recipient_name = '';

    public string $recipient_email = '';

    public string $recipient_phone = '';

    public string $department_id = '';

    public string $activity_category_id = '';

    public string $instructions = '';

    public string $expires_at = '';

    public string $max_submissions = '';

    public ?string $freshUrl = null;

    public function mount(): void
    {
        abort_unless(config('activities.submission_links'), 404);
    }

    public function edit(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->editingId = $id;
        $this->reset(['label', 'recipient_name', 'recipient_email', 'recipient_phone', 'department_id', 'activity_category_id', 'instructions', 'max_submissions']);
        $this->expires_at = today()->addDays(14)->toDateString();

        if ($id) {
            $link = SubmissionLink::query()->findOrFail($id);
            foreach (['label', 'recipient_name', 'recipient_email', 'recipient_phone', 'instructions'] as $f) {
                $this->{$f} = (string) $link->{$f};
            }
            $this->department_id = (string) ($link->department_id ?? '');
            $this->activity_category_id = (string) ($link->activity_category_id ?? '');
            $this->expires_at = $link->expires_at->toDateString();
            $this->max_submissions = (string) ($link->max_submissions ?? '');
        }

        $this->showForm = true;
    }

    public function save(SubmissionLinkService $links): void
    {
        abort_unless(Auth::user()->can('links.manage'), 403);

        $data = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'activity_category_id' => ['nullable', 'exists:activity_categories,id'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(180)->toDateString()],
            'max_submissions' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [], ['expires_at' => 'expiry date']);

        $data['expires_at'] = Carbon::parse($data['expires_at'])->endOfDay();

        if ($this->editingId) {
            $links->update(SubmissionLink::query()->findOrFail($this->editingId), $data);
            $this->toast('Link updated.');
        } else {
            [$link] = $links->create($data, Auth::user());
            $this->freshUrl = $link->url();
        }

        $this->showForm = false;
    }

    public function regenerate(int $id, SubmissionLinkService $links): void
    {
        abort_unless(Auth::user()->can('links.manage'), 403);
        $link = SubmissionLink::query()->findOrFail($id);
        $links->regenerate($link);
        $this->freshUrl = $link->fresh()->url();
        $this->toast('New link issued. The previous one no longer works.');
    }

    public function toggleRevoked(int $id, SubmissionLinkService $links): void
    {
        abort_unless(Auth::user()->can('links.manage'), 403);
        $link = SubmissionLink::query()->findOrFail($id);
        $link->isRevoked() ? $links->reactivate($link) : $links->revoke($link);
        $this->toast($link->fresh()->isRevoked() ? 'Link revoked.' : 'Link reactivated.');
    }

    public function render(): View
    {
        return view('livewire.links', [
            'links' => SubmissionLink::query()->with(['department', 'creator'])->withCount('activities')->latest()->get(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'categories' => ActivityCategory::query()->active()->ordered()->get(['id', 'name']),
        ]);
    }
}
