<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * PAKA-RANGI class recipes (§2.3, §6, §7), declared once. Pages reference
 * these instead of repeating the strings, so buttons and controls cannot drift.
 */
final class Ui
{
    public const BTN_PRIMARY = 'inline-flex items-center justify-center gap-2 text-[13px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 px-4 py-2.5 rounded-lg transition whitespace-nowrap disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40';

    public const BTN_DANGER = 'inline-flex items-center justify-center gap-2 text-[13px] font-semibold text-white bg-red-600 hover:bg-red-700 px-4 py-2.5 rounded-lg transition whitespace-nowrap disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/40';

    public const BTN_NEUTRAL = 'inline-flex items-center justify-center gap-2 text-[13px] font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 px-4 py-2.5 rounded-lg transition whitespace-nowrap disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/30';

    public const BTN_TINT = 'inline-flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-2.5 py-1.5 rounded-lg transition whitespace-nowrap disabled:opacity-60';

    public const BTN_ICON = 'p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition';

    public const BTN_ICON_DANGER = 'p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition';

    public const CONTROL = 'w-full rounded-lg px-3 py-2 bg-gray-50 border border-gray-200 text-sm text-slate-700 hover:border-gray-300 focus:bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all outline-none disabled:opacity-60';

    public const LABEL = 'block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1';

    public const MICRO = 'text-[10px] font-semibold uppercase tracking-wider text-slate-400';

    public const TH = 'px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-400 whitespace-nowrap';

    public const TD = 'px-4 py-3 text-[13px] text-slate-600 align-top';

    public const CARD = 'bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden';

    public const CAPTION = 'shrink-0 flex items-center justify-between gap-3 px-5 py-2.5 bg-slate-50/60 border-b border-slate-100';

    /** Dates are always d M Y (PAKA-RANGI §2.5, FRD 8). */
    public static function date(?Carbon $date): string
    {
        return $date ? $date->format('d M Y') : '—';
    }

    public static function dateRange(?Carbon $start, ?Carbon $end): string
    {
        if (! $start) {
            return '—';
        }

        return ! $end || $start->equalTo($end) ? self::date($start) : self::date($start).' – '.self::date($end);
    }
}
