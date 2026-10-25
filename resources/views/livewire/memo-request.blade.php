@use('App\Support\Ui')
@use('App\Support\Money')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; $file = 'block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold'; @endphp

<div class="space-y-4">
    @if ($done)
        <div class="{{ Ui::CARD }} p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-check text-2xl text-emerald-600"></i></div>
            <h1 class="text-xl font-bold text-slate-800">Submitted</h1>
            <p class="text-sm text-slate-600 mt-1">{{ $done }}</p>
        </div>
    @elseif (! $usable)
        <div class="{{ Ui::CARD }} p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-link-slash text-2xl text-slate-300"></i></div>
            <h1 class="text-xl font-bold text-slate-800">This link is not available</h1>
            <p class="text-sm text-slate-500 mt-1">{{ $link?->isCompleted() ? 'The memo has been submitted to your Head of Department. If they return it to you, a new link will be sent.' : 'It may have expired or been withdrawn. Ask your Head of Department for a new one.' }}</p>
        </div>
    @elseif (! $verified)
        <div class="{{ Ui::CARD }} p-8 max-w-lg mx-auto">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mb-3"><i class="fa-solid fa-shield-halved text-2xl text-emerald-600"></i></div>
            <h1 class="text-xl font-bold text-slate-800">Confirm it is you</h1>
            <p class="text-sm text-slate-600 mt-1">The Head of {{ $link->department?->name ?? 'your department' }} has asked you to prepare an activity memo. To open it, we send a six-digit code to the email and mobile number HR holds for you.</p>
            @if ($codeSentTo !== [])
                <p class="text-[13px] text-emerald-700 mt-3"><i class="fa-solid fa-paper-plane mr-1"></i>Code sent to {{ implode(' and ', $codeSentTo) }}. It works for {{ \App\Services\DelegatedMemos::CODE_MINUTES }} minutes.</p>
                <form wire:submit="verify" class="mt-4 flex gap-2 items-start">
                    <x-ui.field label="Code" for="mr-code" error="code" class="flex-1"><input id="mr-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" wire:model="code" class="{{ Ui::CONTROL }} font-numeric tracking-widest"></x-ui.field>
                    <button type="submit" class="{{ Ui::BTN_PRIMARY }} mt-5" wire:loading.attr="disabled" wire:target="verify">Open the memo</button>
                </form>
                <button type="button" wire:click="sendCode" class="mt-3 text-[12px] font-semibold text-emerald-700 hover:underline">Send another code</button>
            @else
                @error('code')<p class="text-xs text-red-600 mt-3" role="alert">{{ $message }}</p>@enderror
                <button type="button" wire:click="sendCode" class="mt-4 {{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="sendCode"><i class="fa-solid fa-paper-plane text-xs"></i>Send me a code</button>
            @endif
        </div>
    @else
        <div>
            <h1 class="text-xl font-bold text-slate-800">{{ $memo ? $memo->reference.' · '.$memo->title : 'Prepare an activity memo' }}</h1>
            <p class="text-sm text-slate-500">For the Head of {{ $link->department?->name }}. Save your work as you go; submit it to your Head of Department when it is ready. This link works until {{ $link->expires_at->format('d M Y') }}.</p>
            @if ($link->instructions)<div class="mt-3 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-[13px] text-emerald-900 whitespace-pre-line"><p class="font-semibold mb-0.5">From your Head of Department</p>{{ $link->instructions }}</div>@endif
            @if ($returned && $memo->status_reason)<div class="mt-3 px-4 py-3 rounded-lg bg-orange-50 border border-orange-200 text-[13px] text-orange-800 whitespace-pre-line" role="alert"><p class="font-semibold"><i class="fa-solid fa-reply mr-1"></i>Returned to you. Please change:</p>{{ $memo->status_reason }}</div>@endif
            @error('form')<div class="mt-3 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-[13px] text-red-700" role="alert">{{ $message }}</div>@enderror
        </div>

        @if (! $editable)
            <div class="px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-600 text-sm"><i class="fa-solid fa-lock mr-1"></i>This memo is {{ strtolower($memo->status->label()) }}. It can no longer be changed here.</div>
        @else
            <form wire:submit="save" class="space-y-4">
                <x-ui.card title="1. The memo" padded>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.field label="Title" for="mr-t" error="title" required class="sm:col-span-2"><input id="mr-t" type="text" wire:model="title" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                        <x-ui.field label="Category" for="mr-c" error="activity_category_id" required><select id="mr-c" wire:model.live="activity_category_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="Activity type" for="mr-ty" error="activity_type_id"><select id="mr-ty" wire:model="activity_type_id" class="{{ $sel }}" @disabled($types->isEmpty())><option value="">Choose…</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="Purpose (objective)" for="mr-p" error="purpose" required class="sm:col-span-2"><textarea id="mr-p" wire:model="purpose" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                        <x-ui.field label="Introduction and justification" for="mr-j" error="justification" required class="sm:col-span-2" hint="The background, why the activity is needed now, and any letter or Act it answers."><textarea id="mr-j" wire:model="justification" rows="4" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                        <x-ui.field label="Expected outcomes and deliverables" for="mr-o" error="expected_outputs" class="sm:col-span-2"><textarea id="mr-o" wire:model="expected_outputs" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                        <x-ui.field label="Key risks to be mitigated" for="mr-r" error="risks" class="sm:col-span-2"><textarea id="mr-r" wire:model="risks" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                        <x-ui.field label="Participants and stakeholders" for="mr-a" error="target_audience" class="sm:col-span-2"><input id="mr-a" type="text" wire:model="target_audience" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                        <x-ui.field label="Planned start" for="mr-sd" error="start_date" required><input id="mr-sd" type="date" wire:model.live="start_date" min="{{ today()->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
                        <x-ui.field label="Number of days" for="mr-days" error="days" required :hint="$endDate ? 'Ends '.Ui::date($endDate) : null"><input id="mr-days" type="number" min="1" max="120" wire:model.live="days" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                        <x-ui.field label="County" for="mr-co" error="county_id" hint="Needed for allowances (DSA) when officers travel."><select id="mr-co" wire:model.live="county_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="Constituency" for="mr-k" error="constituency_id"><select id="mr-k" wire:model="constituency_id" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">Any</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="Venue" for="mr-v" error="venue" required><input id="mr-v" type="text" wire:model="venue" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                        <x-ui.field label="Estimated budget (KES)" for="mr-b" error="requested_budget"><input id="mr-b" type="text" inputmode="decimal" wire:model="requested_budget" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 250000"></x-ui.field>
                        <x-ui.field label="Notes" for="mr-n" error="notes" class="sm:col-span-2"><textarea id="mr-n" wire:model="notes" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                    </div>
                    <div class="flex justify-end mt-4"><button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-floppy-disk text-xs"></i>{{ $memo ? 'Save changes' : 'Save draft' }}</button></div>
                </x-ui.card>
            </form>

            @if ($memo)
                <x-ui.card title="2. The team" icon="fa-users" :count="$memo->participants->count()" padded>
                    @if ($memo->participants->isNotEmpty())
                        <ul class="divide-y divide-slate-100 mb-4 border border-slate-100 rounded-lg">
                            @foreach ($memo->participants as $p)
                                <li class="px-3 py-2 flex items-center justify-between gap-3 text-[13px]" wire:key="mp-{{ $p->id }}">
                                    <span><span class="font-semibold text-slate-700">{{ $p->displayName() }}</span> <span class="text-slate-400">· {{ $p->role->label() }}</span></span>
                                    <button type="button" wire:click="removeParticipant({{ $p->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove {{ $p->displayName() }}"><i class="fa-solid fa-trash-can text-xs"></i></button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input type="search" wire:model.live.debounce.300ms="staffSearch" class="{{ Ui::CONTROL }}" placeholder="Search the staff list by name or PF number…" aria-label="Search staff">
                        <select wire:model="role" class="{{ $sel }} sm:w-40" aria-label="Role">@foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
                        <button type="button" wire:click="addOfficers" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="addOfficers">Add to team</button>
                    </div>
                    @error('picked')<p class="text-xs text-red-600 mt-1" role="alert">{{ $message }}</p>@enderror
                    @if ($matches->isNotEmpty())
                        <ul class="mt-2 max-h-56 overflow-y-auto border border-slate-200 rounded-lg divide-y divide-slate-100">
                            @foreach ($matches as $s)
                                <li wire:key="ms-{{ $s->id }}"><label class="flex items-center gap-3 px-3 py-2 text-[13px] cursor-pointer hover:bg-emerald-50">
                                    <input type="checkbox" value="{{ $s->id }}" wire:model="picked" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span><span class="font-semibold text-slate-700">{{ $s->name }}</span> <span class="text-slate-400">· {{ $s->designation?->name ?? 'No designation' }} · {{ $s->department?->name ?? 'No department' }}</span></span>
                                </label></li>
                            @endforeach
                        </ul>
                    @endif
                    @foreach ($warnings as $w)<p class="text-[12px] text-amber-700 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ $w }}</p>@endforeach
                    <p class="text-[11px] text-slate-400 mt-2">An officer already on another approved activity on these days cannot be added. Allowances (DSA) are worked out from each officer's job grade.</p>
                </x-ui.card>

                <x-ui.card title="3. Costs" icon="fa-coins" padded>
                    @if ($memo->costs->isNotEmpty())
                        <ul class="divide-y divide-slate-100 mb-4 border border-slate-100 rounded-lg">
                            @foreach ($memo->costs as $c)
                                <li class="px-3 py-2 flex items-center justify-between gap-3 text-[13px]" wire:key="mc-{{ $c->id }}">
                                    <span><span class="font-semibold text-slate-700">{{ $c->category?->name }}</span> <span class="text-slate-400">· {{ $c->participant?->displayName() ?? 'Whole activity' }}{{ $c->description ? ' · '.$c->description : '' }}</span></span>
                                    <span class="flex items-center gap-2"><x-ui.money :value="Money::toCents($c->estimated_amount)" />@unless ($c->is_computed)<button type="button" wire:click="removeCost({{ $c->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove cost line"><i class="fa-solid fa-trash-can text-xs"></i></button>@endunless</span>
                                </li>
                            @endforeach
                            <li class="px-3 py-2 flex justify-between text-[13px] font-semibold bg-slate-50"><span>Total</span><x-ui.money :value="$memo->costs->sum(fn ($c) => Money::toCents($c->estimated_amount))" /></li>
                        </ul>
                    @endif
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 items-start">
                        <x-ui.field label="Cost type" for="mc-c" error="costCategory"><select id="mc-c" wire:model="costCategory" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($costCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="For" for="mc-p" error="costParticipant"><select id="mc-p" wire:model="costParticipant" class="{{ $sel }}"><option value="">The whole activity</option>@foreach ($memo->participants as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}</option>@endforeach</select></x-ui.field>
                        <x-ui.field label="Description" for="mc-d" error="costDescription"><input id="mc-d" type="text" wire:model="costDescription" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                        <x-ui.field label="Amount (KES)" for="mc-a" error="costAmount"><input id="mc-a" type="text" inputmode="decimal" wire:model="costAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                    </div>
                    <div class="flex justify-end mt-2"><button type="button" wire:click="addCost" class="{{ Ui::BTN_TINT }}" wire:loading.attr="disabled" wire:target="addCost"><i class="fa-solid fa-plus text-[9px]"></i>Add cost line</button></div>
                </x-ui.card>

                <x-ui.card title="4. Supporting documents" icon="fa-paperclip" padded>
                    @foreach ($memo->documents as $d)<p class="text-[13px] text-slate-600"><i class="fa-solid fa-file text-xs text-slate-400 mr-1"></i>{{ $d->original_name }}</p>@endforeach
                    <x-ui.field label="Attach a document (optional)" for="md-f" error="document" hint="For example an invitation letter. PDF, Word, Excel or image, up to 10 MB. The memo itself is written by the system."><input id="md-f" type="file" wire:model="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="{{ $file }}"></x-ui.field>
                </x-ui.card>

                <x-ui.card title="5. Check and submit" icon="fa-paper-plane" padded>
                    <p class="text-[13px] text-slate-600 mb-3">The system writes the memo in the Board's format from what you entered. <a href="{{ route('memo-request.preview', $token) }}" target="_blank" rel="noopener" class="font-semibold text-emerald-700 hover:underline"><i class="fa-solid fa-file-pdf text-red-600 mr-1"></i>Preview the memo</a> before you submit.</p>
                    @if ($issues !== [])
                        <ul class="space-y-1.5 mb-3">
                            @foreach ($issues as $issue)
                                <li class="text-[13px] {{ $issue['blocking'] ? 'text-red-700' : 'text-amber-700' }}"><i class="fa-solid {{ $issue['blocking'] ? 'fa-circle-xmark' : 'fa-triangle-exclamation' }} mr-1.5"></i>{{ $issue['message'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($confirmSubmit)
                        <div class="px-3 py-2.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-[13px]">
                            <p>Submit it to your Head of Department? You cannot change it after this unless they return it to you.</p>
                            <div class="flex gap-2 mt-2">
                                <button type="button" wire:click="$set('confirmSubmit', false)" class="{{ Ui::BTN_NEUTRAL }}">Not yet</button>
                                <button type="button" wire:click="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="submit"><i class="fa-solid fa-paper-plane text-xs"></i>Submit to the Head of Department</button>
                            </div>
                        </div>
                    @else
                        <button type="button" wire:click="$set('confirmSubmit', true)" class="{{ Ui::BTN_PRIMARY }}" @disabled(collect($issues)->contains('blocking', true))><i class="fa-solid fa-paper-plane text-xs"></i>Submit to the Head of Department</button>
                    @endif
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
