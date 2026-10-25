<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\ActivityParticipant;
use App\Models\ActivityStatusHistory;
use App\Models\DsaRate;
use App\Models\User;
use App\Support\DocumentFont;
use App\Support\DsaPeriod;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The activity memo the system generates from what was captured, as a PDF in
 * the layout of the Board's internal memo template (files/MEMO Template .docx,
 * matched 25 October 2026): US Letter, the logo and the Board's name, INTERNAL
 * MEMO, TO / FROM / DATE / REF NO / SUBJECT, numbered sections with the risks
 * as bullets, the request for approval and the four signature blocks (the
 * Head's designation as signed, "Ag." when acting); then Annex: A, the Activity
 * Budget Request table with the signatures repeated, and the Participants table.
 *
 * Every figure is the planned (requested) amount from ActivityCosting, so the
 * memo and Annex A always agree with each other and with the activity's
 * stored total. Nothing is filled in that was not captured: what
 * is missing is listed by issues(), and the blocking ones stop a Head of
 * Department's submission.
 */
class MemoDocument
{
    public function __construct(private ParticipantChecks $checks, private DsaCalculator $dsa, private AppSettings $settings) {}

    /**
     * What is missing or inconsistent. Blocking items stop submission for
     * review; the others are shown so the originator can decide.
     *
     * @return list<array{message: string, blocking: bool}>
     */
    public function issues(Activity $activity): array
    {
        $activity->loadMissing(['locations.county', 'participants.staff', 'costs', 'budgetLine']);
        $issues = [];
        $add = function (string $message, bool $blocking, bool $forHead = false) use (&$issues): void {
            $issues[] = ['message' => $message, 'blocking' => $blocking, 'for_head' => $forHead];
        };

        // The Head of Department issues the memo: its reference and date are theirs to give (25 October 2026).
        if ($activity->isDepartmentOriginated()) {
            if (blank(trim((string) $activity->source_reference))) {
                $add('Give the memo reference (REF NO).', true, forHead: true);
            }
            if ($activity->issued_on === null) {
                $add('Give the date the memo is issued.', true, forHead: true);
            }
        }

        if (blank($activity->justification)) {
            $add('Write the introduction and justification (section 1.0 of the memo).', true);
        }

        if ($activity->locations->isEmpty()) {
            $add('Add the venue or location.', true);
        }

        $staff = $activity->participants->where('is_external', false);
        if ($staff->isEmpty()) {
            $add('Add at least one officer to the team.', true);
        }

        foreach ($this->checks->conflictsFor($activity) as $participantId => $clash) {
            $participant = $activity->participants->firstWhere('id', $participantId);
            if ($participant && blank($participant->conflict_reason)) {
                $add("{$participant->displayName()} is not available: already on another approved activity on some of these days. Remove them or ask for their release.", true);
            }
        }

        if (blank($activity->risks)) {
            $add('Key risks to be mitigated (section 3.0) is empty.', false);
        }

        if (! $activity->budget_line_id) {
            $add('No budget line is chosen, so the memo cannot say which vote the cost is charged to.', false);
        }

        if ($staff->isNotEmpty()) {
            if (! $this->dsa->appliesTo($activity)) {
                $add('No location has a county, so no DSA is included. Add the county if officers travel.', false);
            } else {
                $dsaPeople = $activity->costs->where('is_computed', true)->pluck('activity_participant_id')->all();
                foreach ($staff->reject(fn (ActivityParticipant $p) => in_array($p->id, $dsaPeople, true)) as $p) {
                    $grade = DsaRate::gradeFor($p->job_grade ?? $p->staff?->job_grade, $p->staff?->employment_type);
                    $add("No DSA rate was found for {$p->displayName()} (job grade ".($grade ?: 'not set').'), so no DSA is included for them.', false);
                }
            }
        }

        $total = Money::toCents($activity->estimated_total);
        if ($total === 0) {
            $add('The budget has no cost lines yet; the memo would request KES 0.00.', false);
        } elseif ($activity->requested_budget !== null && Money::toCents($activity->requested_budget) !== $total) {
            $add(sprintf('Your own estimate (KES %s) differs from the costed budget (KES %s). The memo uses the costed budget.', Money::format(Money::toCents($activity->requested_budget)), Money::format($total)), false);
        }

        $pending = $this->checks->pendingStaff($activity->start_date, $activity->end_date, $activity->id);
        foreach ($staff as $p) {
            if (isset($pending[$p->staff_id])) {
                $add("{$p->displayName()} is also on a memo awaiting decision on overlapping days. If that memo is approved first, they will not be available.", false);
            }
        }

        return $issues;
    }

    /**
     * $byHead false: what an officer preparing the memo must complete; the reference and issue date are the Head's.
     *
     * @return list<string>
     */
    public function blockingIssues(Activity $activity, bool $byHead = true): array
    {
        return collect($this->issues($activity))->where('blocking', true)->when(! $byHead, fn ($c) => $c->where('for_head', false))->pluck('message')->values()->all();
    }

    /**
     * Everything the memo and its annexes print, figures in cents.
     *
     * @return array<string, mixed>
     */
    public function data(Activity $activity): array
    {
        $activity->loadMissing([
            'organisingDepartment', 'budgetLine', 'locations.county', 'locations.constituency', 'locations.region',
            'participants.staff', 'participants.designation', 'participants.department', 'participants.releases', 'participants.costs',
            'costs.category', 'costs.participant.designation', 'statusHistory.user.roles', 'creator.roles', 'responsibleHod', 'approver',
        ]);

        $lines = $activity->costs;
        $total = $lines->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount));
        $submittedAt = $activity->statusHistory->first(fn (ActivityStatusHistory $h) => in_array($h->to_status, [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision], true))?->created_at;
        $department = $activity->organisingDepartment?->name;

        return [
            'activity' => $activity,
            'isDraft' => $submittedAt === null && ! $activity->status->isApprovedOrLater(),
            'to' => 'Chief Executive Officer',
            'from' => $department ? (str_contains(mb_strtolower($department), 'department') ? $department : $department.' Department') : 'Department not set',
            // A Head's memo prints the date it is issued; an older memo, the date it was submitted, as before.
            'date' => $activity->issued_on ?? $submittedAt ?? today(),
            'refNo' => $activity->source_reference ?: $activity->reference,
            'period' => DsaPeriod::of($activity->start_date, max(1, (int) $activity->days)),
            'nightsBasis' => $this->settings->get('dsa_basis') !== DsaPeriod::BASIS_DAYS,
            'sections' => $this->sections($activity),
            'request' => $this->requestParagraph($activity, $total),
            'signatures' => $this->signatures($activity, $submittedAt),
            'total' => $total,
            'byCategory' => $this->byCategory($lines, $total),
            'byDepartment' => $this->byDepartment($activity, $lines, $total),
            'detailed' => $this->detailedBudget($activity, $lines),
            'itinerary' => $this->itinerary($activity),
            'issues' => $this->issues($activity),
        ];
    }

    public function pdf(Activity $activity, ?User $user = null): DomPdf
    {
        // Subsetting embeds only the characters used (App\Support\DocumentFont), keeping the file small.
        // US Letter, as the Board's memo template (files/MEMO Template .docx).
        $pdf = Pdf::loadView('memos.document', $this->data($activity) + ['user' => $user])->setPaper('letter')->setOption('isFontSubsettingEnabled', true);

        // "Page n of N" needs the page count, which only exists once the document is laid out.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont(DocumentFont::NAME) ?? $metrics->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 40, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0, 0, 0]);

        return $pdf;
    }

    public function filename(Activity $activity): string
    {
        return $activity->reference.'-activity-memo-RESTRICTED.pdf';
    }

    /** @return list<array{number: string, heading: string, paragraphs: list<string>}> */
    private function sections(Activity $activity): array
    {
        $sections = [
            ['heading' => 'Introduction', 'paragraphs' => [(string) $activity->justification]],
            ['heading' => 'Objectives', 'paragraphs' => array_values(array_filter([
                (string) $activity->purpose,
                filled($activity->expected_outputs) ? 'Expected outcomes: '.$activity->expected_outputs : null,
                filled($activity->target_audience) ? 'Participants and stakeholders: '.$activity->target_audience : null,
            ]))],
        ];

        if (filled($activity->risks)) {
            // Each line of the risks is a bullet, as in the template.
            $risks = array_values(array_filter(array_map(fn (string $l) => trim(ltrim(trim($l), "-•*\t ")), preg_split('/\R/', (string) $activity->risks))));
            $sections[] = ['heading' => 'Key Risks to be mitigated', 'paragraphs' => [], 'bullets' => $risks];
        }

        foreach ((array) $activity->memo_sections as $extra) {
            if (filled($extra['heading'] ?? null) && filled($extra['body'] ?? null)) {
                $sections[] = ['heading' => (string) $extra['heading'], 'paragraphs' => [(string) $extra['body']]];
            }
        }

        return collect($sections)->values()->map(fn (array $s, int $i) => $s + ['number' => ($i + 1).'.0', 'bullets' => []])->all();
    }

    private function requestParagraph(Activity $activity, int $total): string
    {
        $people = $activity->participants->where('is_external', false)->sum(fn (ActivityParticipant $p) => $p->people());
        $externals = $activity->participants->where('is_external', true)->count();
        $team = $people.' '.($people === 1 ? 'officer' : 'officers').($externals ? " and {$externals} external ".($externals === 1 ? 'participant' : 'participants') : '');
        $vote = $activity->budgetLine ? 'charged under '.$activity->budgetLine->label() : 'charged to the department\'s vote';

        return sprintf(
            'The estimated expenditure amounting to <strong>Kshs.%s</strong>, for %s over %d %s (%s to %s) at %s, shall be %s. Details of the proposed participants and the corresponding budget breakdown are provided in Annex: A attached hereto.',
            e(number_format(intdiv($total, 100)).($total % 100 ? '.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT) : '')), e($team), $activity->days, $activity->days === 1 ? 'day' : 'days',
            e($activity->start_date->format('jS F Y')), e($activity->end_date->format('jS F Y')),
            e($activity->locationSummary() ?: 'the venue stated'), e($vote),
        );
    }

    /**
     * Prepared, reviewed, validated and approved, filled from the audit trail
     * of the latest round. A step not yet taken is left blank to be signed.
     *
     * @return list<array{label: string, name: ?string, designation: string, date: ?Carbon}>
     */
    private function signatures(Activity $activity, ?Carbon $submittedAt): array
    {
        // Forwards since the memo last left the department.
        $fromDepartment = [ActivityStatus::Draft, ActivityStatus::ReturnedToHod, ActivityStatus::WithHod];
        $lastSubmission = $activity->statusHistory->filter(fn (ActivityStatusHistory $h) => $h->to_status === ActivityStatus::UnderReview && in_array($h->from_status, $fromDepartment, true))->last();
        $forwards = $activity->statusHistory
            ->filter(fn (ActivityStatusHistory $h) => ! $lastSubmission || $h->id > $lastSubmission->id)
            ->filter(fn (ActivityStatusHistory $h) => $h->to_status === ActivityStatus::AwaitingDecision || ($h->to_status === ActivityStatus::UnderReview && $h->from_status === ActivityStatus::UnderReview));
        $by = fn (string $role) => $forwards->filter(fn (ActivityStatusHistory $h) => $h->user?->hasRole($role))->last();

        $acos = $by(User::ROLE_ASSISTANT_CHIEF_OF_STAFF);
        $cos = $by(User::ROLE_CHIEF_OF_STAFF);
        $preparer = $activity->creator;
        $department = $activity->organisingDepartment?->name;

        // The Head signs with their designation as recorded when they submitted ("Ag." if acting);
        // before that, with their designation on the staff list.
        $hodDesignation = $activity->signedDesignation()
            ?? ($preparer?->isHeadOfDepartment() ? $preparer->signingDesignation() : null);

        return [
            [
                'label' => 'Prepared by',
                'name' => $preparer?->name ?? $activity->submitted_by_name,
                'designation' => $activity->isPreparedOnBehalf()
                    ? ($preparer?->roleLabel() ?? 'Office of the CEO').', on behalf of '.$activity->responsibleHodLabel().', Head of Department'.($department ? ", {$department}" : '')
                    : ($hodDesignation ?? ($preparer?->roleLabel() ?? '')),
                'date' => $submittedAt,
            ],
            ['label' => 'Reviewed by', 'name' => $acos?->user?->name, 'designation' => User::ROLES[User::ROLE_ASSISTANT_CHIEF_OF_STAFF], 'date' => $acos?->created_at],
            ['label' => 'Validated by', 'name' => $cos?->user?->name, 'designation' => User::ROLES[User::ROLE_CHIEF_OF_STAFF], 'date' => $cos?->created_at],
            ['label' => 'Approved by', 'name' => $activity->approved_at ? ($activity->approver?->name ?? 'Chief Executive Officer') : null, 'designation' => User::ROLES[User::ROLE_CEO], 'date' => $activity->approved_at],
        ];
    }

    /**
     * Annex A by cost item.
     *
     * @param  Collection<int, ActivityCost>  $lines
     * @return list<array{label: string, amount: int, share: int}>
     */
    private function byCategory(Collection $lines, int $total): array
    {
        return $lines->groupBy('cost_category_id')
            ->map(fn (Collection $group) => [
                'label' => (string) $group->first()->category?->name,
                'amount' => $group->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount)),
                'sort' => (int) $group->first()->category?->sort_order,
            ])
            ->sortBy('sort')
            ->map(fn (array $r) => ['label' => $r['label'], 'amount' => $r['amount'], 'share' => $this->share($r['amount'], $total)])
            ->values()->all();
    }

    /**
     * Annex A by department: each officer's own lines under the department
     * they are in, external participants' lines, and the shared costs.
     *
     * @param  Collection<int, ActivityCost>  $lines
     * @return list<array{label: string, people: ?int, amount: int, share: int}>
     */
    private function byDepartment(Activity $activity, Collection $lines, int $total): array
    {
        $people = $activity->participants->keyBy('id');
        $rows = [];

        foreach ($lines as $line) {
            $person = $line->activity_participant_id ? $people->get($line->activity_participant_id) : null;
            $key = match (true) {
                $person === null => '~shared',
                $person->is_external => '~external',
                default => (string) ($person->department?->name ?? 'No department'),
            };
            $rows[$key] = ($rows[$key] ?? 0) + Money::toCents($line->estimated_amount);
        }

        $headcount = fn (string $key) => match ($key) {
            '~shared' => null,
            '~external' => $activity->participants->where('is_external', true)->count(),
            default => $activity->participants->where('is_external', false)->filter(fn ($p) => ($p->department?->name ?? 'No department') === $key)->count(),
        };

        return collect($rows)
            ->sortKeys()
            ->map(fn (int $amount, string $key) => [
                'label' => match ($key) {
                    '~shared' => 'Shared costs (whole activity)',
                    '~external' => 'External participants',
                    default => $key,
                },
                'people' => $headcount($key),
                'amount' => $amount,
                'share' => $this->share($amount, $total),
            ])->values()->all();
    }

    /**
     * Annex B, in the template's columns. Identical per-person lines (same
     * item, designation, scale, days and rate) are grouped as one row with
     * the number of people, so the table reads like the paper budget.
     *
     * @param  Collection<int, ActivityCost>  $lines
     * @return list<array{item: string, category: string, scale: string, pax: ?int, days: ?string, rate: ?int, amount: int}>
     */
    private function detailedBudget(Activity $activity, Collection $lines): array
    {
        $people = $activity->participants->keyBy('id');

        return $lines
            ->map(function (ActivityCost $line) use ($people) {
                $person = $line->activity_participant_id ? $people->get($line->activity_participant_id) : null;
                $amount = Money::toCents($line->estimated_amount);
                $item = $line->category?->name.($line->is_computed || blank($line->description) ? '' : ': '.$line->description).($line->isOverridden() ? ' (adjusted)' : '');

                return [
                    'id' => $line->id,
                    'item' => $item,
                    'category' => match (true) {
                        $person === null => 'Whole activity',
                        $person->isGroup() => (string) $person->group_label,
                        $person->is_external => 'External: '.($person->external_category?->label() ?? 'Other'),
                        default => $person->designation?->name ?? 'Officer',
                    },
                    // The scale DSA is costed at: the officer's own, or their terms' (scale 10 for a casual, INTERN for an attachee).
                    'scale' => $person && ! $person->is_external ? (string) (DsaRate::normaliseGrade(DsaRate::gradeFor($person->job_grade, $person->staff?->employment_type)) ?? $person->job_grade ?? '') : '',
                    'per_person' => $person !== null,
                    'people' => $person?->people() ?? 1,
                    'days' => $line->quantity !== null ? rtrim(rtrim((string) $line->quantity, '0'), '.') : null,
                    'rate' => $line->unit_rate !== null ? Money::toCents($line->unit_rate) : ($person ? $amount : null),
                    'amount' => $amount,
                    'sort' => [(int) $line->category?->sort_order, $person === null ? 1 : 0],
                ];
            })
            ->groupBy(fn (array $r) => implode('|', [$r['item'], $r['category'], $r['scale'], $r['days'], $r['rate'], $r['per_person'] ? $r['amount'] : 'shared-'.$r['id']]))
            ->map(fn (Collection $group) => [
                'item' => $group->first()['item'],
                'category' => $group->first()['category'],
                'scale' => $group->first()['scale'],
                'pax' => $group->first()['per_person'] ? $group->sum('people') : null,
                'days' => $group->first()['days'],
                'rate' => $group->first()['rate'],
                'amount' => $group->sum('amount'),
                'sort' => $group->first()['sort'],
            ])
            ->sortBy([fn ($a, $b) => $a['sort'] <=> $b['sort'], fn ($a, $b) => strcmp($a['category'], $b['category'])])
            ->map(fn (array $r) => collect($r)->except('sort')->all())
            ->values()->all();
    }

    /**
     * Annex C: who goes, and the days each one is on the activity.
     *
     * @return list<array<string, mixed>>
     */
    private function itinerary(Activity $activity): array
    {
        $order = ['lead' => 0, 'member' => 1, 'support' => 2, 'driver' => 3];

        return $activity->participants
            ->sortBy(fn (ActivityParticipant $p) => sprintf('%d-%d-%s', $p->is_external ? 1 : 0, $order[$p->role->value] ?? 9, mb_strtolower($p->displayName())))
            ->values()
            ->map(function (ActivityParticipant $p) use ($activity) {
                $release = $p->releases->last();
                $travel = $p->costs->first(fn (ActivityCost $c) => $c->travel_mode !== null)?->travel_mode?->label();

                return [
                    'name' => $p->isGroup() ? (string) $p->group_label : $p->displayName(),
                    'number' => $p->is_external ? '' : (string) $p->staff?->staff_number,
                    'designation' => match (true) {
                        $p->is_external => (string) $p->external_organisation,
                        $p->isGroup() => $p->people().' officers (scale '.$p->job_grade.')',
                        default => (string) $p->designation?->name,
                    },
                    'department' => $p->is_external ? 'External' : (string) $p->department?->name,
                    'role' => $p->role->label(),
                    'from' => $p->startOn($activity),
                    'to' => $p->endOn($activity),
                    'days' => $p->plannedDays($activity),
                    'travel' => $travel,
                    'note' => $release ? $release->kindLabel().'; last day '.$release->last_day->format('d M Y') : null,
                ];
            })->all();
    }

    private function share(int $amount, int $total): int
    {
        return $total > 0 ? intdiv($amount * 1000 + 5 * $total, $total * 10) : 0;
    }
}
