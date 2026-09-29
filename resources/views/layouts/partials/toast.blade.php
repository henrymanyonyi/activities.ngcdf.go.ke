{{-- Toast, as in Smart: components dispatch('notify', message: '...', type: 'success|error|warning|info'). --}}
<div x-data="{
        show: false, message: '', type: 'success', timer: null,
        notify(e) {
            this.message = e.detail.message ?? (e.detail[0]?.message ?? '');
            this.type = e.detail.type ?? (e.detail[0]?.type ?? 'success');
            this.show = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.show = false, 6000);
        },
    }"
    x-init="@if (session('notify')) $nextTick(() => notify({ detail: @js(session('notify')) })) @endif"
    x-on:notify.window="notify($event)" x-show="show" x-cloak role="status" aria-live="polite"
    x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
    class="fixed top-6 right-6 z-[60] w-[360px] max-w-[calc(100vw-2rem)] rounded-xl overflow-hidden shadow-lg bg-white border border-slate-200">
    <div class="flex items-start gap-3 px-4 py-3.5">
        <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"
            :class="{
                'bg-emerald-50 text-emerald-700': type === 'success',
                'bg-red-50 text-red-700': type === 'error',
                'bg-amber-50 text-amber-700': type === 'warning',
                'bg-slate-100 text-slate-600': type === 'info',
            }">
            <i class="fa-solid" :class="{
                'fa-circle-check': type === 'success',
                'fa-circle-xmark': type === 'error',
                'fa-triangle-exclamation': type === 'warning',
                'fa-circle-info': type === 'info',
            }"></i>
        </div>
        <p class="flex-1 text-sm text-slate-700 pt-1.5" x-text="message"></p>
        <button type="button" @click="show = false" class="text-slate-400 hover:text-slate-600 p-1" aria-label="Dismiss">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>
