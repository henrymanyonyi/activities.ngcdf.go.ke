@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\CostScope')
@use('App\Services\AppSettings')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp

<x-ui.page icon="fa-sliders" title="Reference data" subtitle="Maintained by the Chief of Staff. Nothing is deleted: deactivate or merge instead.">
    <section class="{{ Ui::CARD }}">
        <div role="tablist" class="flex items-stretch gap-0.5 px-3 pt-2 bg-slate-50/60 border-b border-slate-200 overflow-x-auto">
            @foreach ($tabs as $key => $cfg)
                @php $on = $tab === $key; @endphp
                <button type="button" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" class="inline-flex items-center gap-2 -mb-px px-4 py-2.5 text-[13px] font-semibold whitespace-nowrap rounded-t-lg border-b-2 transition {{ $on ? 'bg-white text-emerald-700 border-emerald-600' : 'text-slate-500 border-transparent hover:text-slate-800 hover:bg-white/70' }}">
                    <i class="fa-solid {{ $cfg['icon'] }} text-[10px] {{ $on ? 'text-emerald-600' : 'text-slate-400' }}"></i>{{ $cfg['label'] }}
                </button>
            @endforeach
        </div>

        <div class="p-5">
            {{-- Name lists: departments, designations, duty stations, categories, types --}}
            @if (in_array($tab, ['departments', 'designations', 'offices', 'categories', 'types']))
                <form wire:submit="add" class="flex flex-col sm:flex-row gap-2 mb-4">
                    @if ($tab === 'types')
                        <select wire:model="newParent" class="{{ $sel }} sm:w-64" aria-label="Category"><option value="">Category…</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                    @endif
                    <input type="text" wire:model="newName" maxlength="255" placeholder="Add a new {{ strtolower($tabs[$tab]['label']) }} entry…" class="{{ Ui::CONTROL }} flex-1" aria-label="New name">
                    <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="add"><i class="fa-solid fa-plus text-xs"></i>Add</button>
                </form>
                @error('newName')<p class="text-xs text-red-600 -mt-2 mb-3">{{ $message }}</p>@enderror
                @error('newParent')<p class="text-xs text-red-600 -mt-2 mb-3">{{ $message }}</p>@enderror

                <div class="divide-y divide-slate-100 border border-slate-100 rounded-xl">
                    @foreach ($items as $item)
                        <div class="flex items-center gap-3 px-4 py-2.5 {{ $item->is_active ? '' : 'opacity-50' }}" wire:key="i-{{ $tab }}-{{ $item->id }}">
                            @if ($editingId === $item->id)
                                <input type="text" wire:model="editName" wire:keydown.enter="rename" class="{{ Ui::CONTROL }} flex-1" aria-label="Name">
                                <button type="button" wire:click="rename" class="{{ Ui::BTN_TINT }}">Save</button>
                                <button type="button" wire:click="$set('editingId', null)" class="{{ Ui::BTN_ICON }}" aria-label="Cancel"><i class="fa-solid fa-xmark text-xs"></i></button>
                            @else
                                <span class="flex-1 text-[13px] text-slate-700">
                                    @if ($tab === 'types')<span class="text-slate-400">{{ $item->category?->name }} ›</span> @endif{{ $item->name }}
                                    @if ($tab === 'categories')<span class="text-[11px] text-slate-400 font-numeric"> · {{ $item->types_count }} types</span>@endif
                                    @unless ($item->is_active)<x-ui.badge class="ml-1">Inactive</x-ui.badge>@endunless
                                </span>
                                <button type="button" wire:click="startEdit({{ $item->id }}, @js($item->name))" class="{{ Ui::BTN_ICON }}" aria-label="Rename"><i class="fa-solid fa-pen text-xs"></i></button>
                                @if (in_array($tab, ['departments', 'designations', 'offices']) && $item->is_active)
                                    <button type="button" wire:click="openMerge({{ $item->id }})" class="{{ Ui::BTN_ICON }}" title="Merge into another entry"><i class="fa-solid fa-code-merge text-xs"></i></button>
                                @endif
                                <button type="button" wire:click="toggleActive({{ $item->id }})" class="{{ Ui::BTN_TINT }}">{{ $item->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($tab === 'costs')
                <p class="text-[13px] text-slate-500 mb-3">DSA is always computed from the rate table. The scope is the usual basis; each cost line can still be set per participant or shared.</p>
                <div class="divide-y divide-slate-100 border border-slate-100 rounded-xl">
                    @foreach ($items as $c)
                        <div class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-2.5 {{ $c->is_active ? '' : 'opacity-50' }}" wire:key="c-{{ $c->id }}">
                            <span class="flex-1 text-[13px] text-slate-700">{{ $c->name }} <span class="text-[11px] text-slate-400">· {{ ucfirst($c->group()) }}</span></span>
                            <select wire:change="setCostScope({{ $c->id }}, $event.target.value)" class="{{ $sel }} sm:w-56 !py-1" aria-label="Scope">@foreach (CostScope::cases() as $scope)<option value="{{ $scope->value }}" @selected($c->default_scope === $scope)>{{ $scope->label() }}</option>@endforeach</select>
                            @if ($c->code !== 'dsa')<button type="button" wire:click="toggleCost({{ $c->id }})" class="{{ Ui::BTN_TINT }}">{{ $c->is_active ? 'Deactivate' : 'Reactivate' }}</button>@endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($tab === 'destinations')
                <p class="text-[13px] text-slate-500 mb-3">The DSA destination category of each county (OI-01). Categories must match those used in the DSA rate table. Provisional values were set at installation.</p>
                <datalist id="dest-list">@foreach ($known as $k)<option value="{{ $k }}">@endforeach</datalist>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2">
                    @foreach ($items as $county)
                        <label class="flex items-center gap-3 text-[13px] text-slate-600" wire:key="co-{{ $county->id }}">
                            <span class="w-36 truncate">{{ $county->name }}</span>
                            <input type="text" list="dest-list" wire:model="destinations.{{ $county->id }}" maxlength="40" class="{{ Ui::CONTROL }} !py-1 flex-1">
                        </label>
                    @endforeach
                </div>
                <div class="flex justify-end mt-4"><button type="button" wire:click="saveDestinations" wire:loading.attr="disabled" wire:target="saveDestinations" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button></div>
            @endif

            @if ($tab === 'rates')
                <form wire:submit="addRate" class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-4 items-end">
                    <x-ui.field label="Job grade" for="r-g" error="rateGrade"><input id="r-g" type="text" list="grade-list" wire:model="rateGrade" maxlength="20" class="{{ Ui::CONTROL }}"><datalist id="grade-list">@foreach ($grades as $g)<option value="{{ $g }}">@endforeach</datalist></x-ui.field>
                    <x-ui.field label="Destination" for="r-d" error="rateDestination"><input id="r-d" type="text" list="dest-list2" wire:model="rateDestination" maxlength="40" class="{{ Ui::CONTROL }}"><datalist id="dest-list2">@foreach ($known as $k)<option value="{{ $k }}">@endforeach</datalist></x-ui.field>
                    <x-ui.field label="Rate per night / day (KES)" for="r-a" error="rateAmount"><input id="r-a" type="text" inputmode="decimal" wire:model="rateAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                    <x-ui.field label="Effective from" for="r-f" error="rateFrom"><input id="r-f" type="date" wire:model="rateFrom" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="addRate"><i class="fa-solid fa-plus text-xs"></i>Add rate</button>
                </form>
                @if ($items->isEmpty())
                    <x-ui.empty icon="fa-money-bill-wave" title="No DSA rates" line="Enter the rates from the Finance circular. Without them DSA cannot be computed." />
                @else
                    <table class="w-full">
                        <thead class="border-b border-slate-100"><tr><th class="{{ Ui::TH }}">Job grade</th><th class="{{ Ui::TH }}">Destination</th><th class="{{ Ui::TH }} text-right">Rate</th><th class="{{ Ui::TH }}">Effective</th><th class="{{ Ui::TH }}"></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($items as $r)
                                <tr wire:key="rate-{{ $r->id }}" class="{{ $r->effective_to && $r->effective_to->isPast() ? 'opacity-50' : '' }}">
                                    <td class="{{ Ui::TD }} font-numeric">{{ $r->job_grade }}</td><td class="{{ Ui::TD }}">{{ $r->destination_category }}</td>
                                    <td class="{{ Ui::TD }} text-right"><x-ui.money :value="$r->amount" /></td>
                                    <td class="{{ Ui::TD }} font-numeric">{{ Ui::date($r->effective_from) }} – {{ $r->effective_to ? Ui::date($r->effective_to) : 'current' }}</td>
                                    <td class="{{ Ui::TD }}">@if (! $r->effective_to)<x-ui.badge classes="bg-emerald-50 text-emerald-700">In force</x-ui.badge>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif

            @if ($tab === 'budgets')
                <form wire:submit="addBudgetLine" class="grid grid-cols-1 sm:grid-cols-6 gap-3 mb-4 items-end">
                    <x-ui.field label="Department" for="b-d" error="budgetDepartment" class="sm:col-span-2"><select id="b-d" wire:model="budgetDepartment" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Year" for="b-y" error="budgetYear"><select id="b-y" wire:model="budgetYear" class="{{ $sel }}">@foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Code" for="b-c" error="budgetCode"><input id="b-c" type="text" wire:model="budgetCode" maxlength="40" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Name" for="b-n" error="budgetName"><input id="b-n" type="text" wire:model="budgetName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Allocation (KES)" for="b-a" error="budgetAmount"><input id="b-a" type="text" inputmode="decimal" wire:model="budgetAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                    <div class="sm:col-span-6 flex justify-end"><button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="addBudgetLine"><i class="fa-solid fa-plus text-xs"></i>Add budget line</button></div>
                </form>
                @if ($items->isEmpty())
                    <x-ui.empty icon="fa-wallet" title="No budget lines" line="Optional (MD-08, OI-08). With them, department costs show the allocation and what remains." />
                @else
                    <table class="w-full">
                        <thead class="border-b border-slate-100"><tr><th class="{{ Ui::TH }}">Year</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Line</th><th class="{{ Ui::TH }} text-right">Allocation</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($items as $b)
                                <tr wire:key="b-{{ $b->id }}"><td class="{{ Ui::TD }} font-numeric">{{ $b->financial_year }}</td><td class="{{ Ui::TD }}">{{ $b->department?->name }}</td><td class="{{ Ui::TD }}">{{ $b->code ? $b->code.' · ' : '' }}{{ $b->name }}</td><td class="{{ Ui::TD }} text-right"><x-ui.money :value="$b->amount" /></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif

            @if ($tab === 'thresholds')
                <form wire:submit="saveSettings" class="space-y-4 max-w-3xl">
                    @foreach (AppSettings::DEFINITIONS as $key => [$label, $default, $help])
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-start">
                            <label for="set-{{ $key }}" class="text-[13px] font-semibold text-slate-700 sm:pt-2">{{ $label }}</label>
                            <div class="sm:col-span-2">
                                @if ($key === 'dsa_basis')
                                    <select id="set-{{ $key }}" wire:model="settings.{{ $key }}" class="{{ $sel }}"><option value="nights">Per night away</option><option value="days">Per day of activity</option></select>
                                @else
                                    <input id="set-{{ $key }}" type="number" min="0" max="1000" wire:model="settings.{{ $key }}" class="{{ Ui::CONTROL }} font-numeric w-40">
                                @endif
                                <p class="text-[11px] text-slate-400 mt-1">{{ $help }} Default: {{ $default }}.</p>
                                @error("settings.{$key}")<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                    <div class="flex justify-end"><button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="saveSettings"><i class="fa-solid fa-floppy-disk text-xs"></i>Save thresholds</button></div>
                </form>
            @endif
        </div>
    </section>

    <x-ui.modal show="showMerge" :open="$showMerge" size="slim" icon="fa-code-merge" title="Merge a duplicate" subtitle="Every record pointing at this entry is moved to the one you keep. The duplicate stays, inactive, for the record.">
        <x-ui.field label="Keep" for="m-into" error="mergeInto" required>
            <select id="m-into" wire:model="mergeInto" class="{{ $sel }}"><option value="">Choose the entry to keep…</option>@foreach ($mergeOptions as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showMerge', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="merge" wire:loading.attr="disabled" wire:target="merge" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-code-merge text-xs"></i>Merge</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
