{{-- The statement in force, styled for the web. The wording lives in the versioned view under privacy/statements. --}}
<article
    class="text-sm text-slate-600 leading-relaxed space-y-3
        [&_h2]:text-[15px] [&_h2]:font-bold [&_h2]:text-slate-800 [&_h2]:pt-4 [&_h2:first-child]:pt-0
        [&_h3]:text-[13px] [&_h3]:font-semibold [&_h3]:text-slate-700 [&_h3]:pt-1
        [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1.5
        [&_dl]:rounded-xl [&_dl]:border [&_dl]:border-slate-100 [&_dl]:divide-y [&_dl]:divide-slate-100 [&_dl]:overflow-hidden
        [&_dt]:px-4 [&_dt]:pt-2.5 [&_dt]:text-[13px] [&_dt]:font-semibold [&_dt]:text-slate-700 [&_dt]:border-0
        [&_dd]:px-4 [&_dd]:pb-2.5 [&_dd]:pt-0.5
        [&_a]:text-emerald-700 [&_a]:font-medium hover:[&_a]:underline">
    @include(config('privacy.statement.view'))
</article>
