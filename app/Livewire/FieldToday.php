<?php

namespace App\Livewire;

use App\Services\Analytics;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** FRD EX-05: every officer currently on an activity, with location and return date. */
#[Layout('layouts.admin')]
#[Title('In the field today')]
class FieldToday extends Component
{
    public string $search = '';

    public function render(Analytics $analytics): View
    {
        $term = mb_strtolower(trim($this->search));
        $people = $analytics->inTheFieldToday()->filter(fn ($p) => $term === ''
            || str_contains(mb_strtolower((string) $p->staff?->name), $term)
            || str_contains(mb_strtolower((string) $p->activity->title), $term)
            || str_contains(mb_strtolower((string) $p->staff?->department?->name), $term));

        return view('livewire.field-today', ['people' => $people]);
    }
}
