@use('App\Support\Ui')
<x-ui.page icon="fa-file-lines" title="Reports" subtitle="Standard reports. Exports carry the RESTRICTED marking, your name and the time, and are logged.">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($reports as $r)
            <a href="{{ route('reports.show', $r->key()) }}" wire:navigate class="{{ Ui::CARD }} p-5 flex gap-4 hover:border-emerald-300 transition">
                <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><i class="fa-solid {{ $r->icon() }}"></i></span>
                <span><span class="block text-sm font-semibold text-slate-800">{{ $r->title() }}</span><span class="block text-[13px] text-slate-500 mt-0.5">{{ $r->description() }}</span></span>
            </a>
        @endforeach
        <a href="{{ route('participation') }}" wire:navigate class="{{ Ui::CARD }} p-5 flex gap-4 hover:border-emerald-300 transition">
            <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><i class="fa-solid fa-users-viewfinder"></i></span>
            <span><span class="block text-sm font-semibold text-slate-800">Staff participation</span><span class="block text-[13px] text-slate-500 mt-0.5">Per officer, expandable, with staff who did not take part.</span></span>
        </a>
    </div>
</x-ui.page>
