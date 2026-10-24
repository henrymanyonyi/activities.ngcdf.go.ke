<?php

namespace App\Livewire\Settings;

use App\Enums\CostScope;
use App\Livewire\Concerns\RunsActions;
use App\Models\ActivityCategory;
use App\Models\ActivityType;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\Designation;
use App\Models\DsaRate;
use App\Models\Office;
use App\Models\Region;
use App\Services\AppSettings;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FRD MD-02..MD-08 and the configurable thresholds, maintained by the Chief of
 * Staff. Nothing here is deleted: lists are deactivated, duplicates merged,
 * DSA rates end-dated so past activities keep their rate.
 */
#[Layout('layouts.admin')]
#[Title('Reference data')]
class Reference extends Component
{
    use RunsActions;

    /** Name-keyed lists and every column that points at them (used by merge). */
    private const LOOKUPS = [
        'departments' => [Department::class, 'Department', [['staff', 'department_id'], ['activities', 'organising_department_id'], ['activity_participants', 'department_id'], ['directives', 'responsible_department_id'], ['budget_lines', 'department_id'], ['department_budgets', 'department_id'], ['submission_links', 'department_id']]],
        'designations' => [Designation::class, 'Designation', [['staff', 'designation_id'], ['activity_participants', 'designation_id']]],
        'offices' => [Office::class, 'Duty station', [['staff', 'office_id'], ['activity_participants', 'office_id']]],
    ];

    #[Url(except: 'departments')]
    public string $tab = 'departments';

    public string $newName = '';

    public string $newParent = '';

    public ?int $editingId = null;

    public string $editName = '';

    public ?int $mergeFrom = null;

    public string $mergeInto = '';

    public bool $showMerge = false;

    // DSA rates
    public string $rateGrade = '';

    public string $rateAmount = '';

    public string $rateFrom = '';

    /** @var array<string, string> */
    public array $settings = [];

    public function mount(AppSettings $appSettings): void
    {
        $this->settings = collect($appSettings->all())->map(fn ($v) => (string) $v)->all();
        $this->rateFrom = today()->toDateString();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->reset(['newName', 'newParent', 'editingId', 'editName']);
        $this->resetErrorBag();
    }

    private function guard(): void
    {
        abort_unless(Auth::user()->can('reference.manage'), 403);
    }

    // ── Name lists ─────────────────────────────────────────────────────

    public function add(): void
    {
        $this->guard();

        if ($this->tab === 'types') {
            $this->validate([
                'newParent' => ['required', 'exists:activity_categories,id'],
                'newName' => ['required', 'string', 'max:255', Rule::unique('activity_types', 'name')->where('activity_category_id', $this->newParent)],
            ], [], ['newParent' => 'category', 'newName' => 'type']);
            ActivityType::create(['activity_category_id' => $this->newParent, 'name' => trim($this->newName)]);
        } elseif ($this->tab === 'categories') {
            $this->validate(['newName' => ['required', 'string', 'max:255', 'unique:activity_categories,name']], [], ['newName' => 'category']);
            ActivityCategory::create(['name' => trim($this->newName)]);
        } else {
            [$class, $label] = self::LOOKUPS[$this->tab];
            $table = (new $class)->getTable();
            $this->validate(['newName' => ['required', 'string', 'max:255', "unique:{$table},name"]], [], ['newName' => strtolower($label)]);
            $class::create(['name' => $class::normaliseName($this->newName)]);
        }

        $this->reset(['newName']);
        $this->toast('Added.');
    }

    public function startEdit(int $id, string $name): void
    {
        $this->editingId = $id;
        $this->editName = $name;
    }

    public function rename(): void
    {
        $this->guard();
        $model = $this->modelFor($this->tab)::query()->findOrFail($this->editingId);
        $this->validate(['editName' => ['required', 'string', 'max:255', Rule::unique($model->getTable(), 'name')->ignore($model->id)]], [], ['editName' => 'name']);

        $model->update(['name' => trim($this->editName)]);
        $this->reset(['editingId', 'editName']);
        $this->toast('Renamed.');
    }

    public function toggleActive(int $id): void
    {
        $this->guard();
        $model = $this->modelFor($this->tab)::query()->findOrFail($id);
        $model->update(['is_active' => ! $model->is_active]);
    }

    public function openMerge(int $id): void
    {
        $this->mergeFrom = $id;
        $this->mergeInto = '';
        $this->showMerge = true;
    }

    /** Point every record at the kept entry, then deactivate the duplicate. */
    public function merge(): void
    {
        $this->guard();
        abort_unless(isset(self::LOOKUPS[$this->tab]), 404);
        [$class, , $references] = self::LOOKUPS[$this->tab];
        $this->validate(['mergeInto' => ['required', Rule::exists((new $class)->getTable(), 'id'), Rule::notIn([$this->mergeFrom])]], [], ['mergeInto' => 'entry to keep']);

        DB::transaction(function () use ($class, $references) {
            foreach ($references as [$table, $column]) {
                DB::table($table)->where($column, $this->mergeFrom)->update([$column => $this->mergeInto]);
            }
            $duplicate = $class::query()->findOrFail($this->mergeFrom);
            app(AuditLogger::class)->record($duplicate, 'merged', ['id' => $duplicate->id, 'name' => $duplicate->name], ['merged_into' => (int) $this->mergeInto]);
            $duplicate->update(['is_active' => false, 'name' => mb_substr($duplicate->name.' (merged #'.$duplicate->id.')', 0, 255)]);
        });

        $this->reset(['showMerge', 'mergeFrom', 'mergeInto']);
        $this->toast('Merged. The duplicate is kept, inactive, for the record.');
    }

    // ── Cost types, counties, DSA rates, thresholds ──────────────────────

    public function setCostScope(int $id, string $scope): void
    {
        $this->guard();
        CostCategory::query()->findOrFail($id)->update(['default_scope' => CostScope::from($scope)]);
    }

    public function toggleCost(int $id): void
    {
        $this->guard();
        $category = CostCategory::query()->findOrFail($id);
        abort_if($category->code === 'dsa', 422);
        $category->update(['is_active' => ! $category->is_active]);
    }

    public function addRate(): void
    {
        $this->guard();
        $this->validate([
            'rateGrade' => ['required', 'string', 'max:20'],
            'rateAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'rateFrom' => ['required', 'date'],
        ], ['rateAmount.regex' => 'Enter an amount such as 8,400.00.'], ['rateGrade' => 'job grade', 'rateAmount' => 'rate', 'rateFrom' => 'effective from']);

        DB::transaction(function () {
            $grade = DsaRate::normaliseGrade($this->rateGrade) ?? strtoupper(trim($this->rateGrade));
            $from = Carbon::parse($this->rateFrom);

            // End-date the rate currently open for this grade (MD-05: history kept). One rate per grade, whatever the destination.
            DsaRate::query()->standard()->whereRaw('UPPER(job_grade) = ?', [$grade])
                ->whereNull('effective_to')->whereDate('effective_from', '<', $from)
                ->get()->each(fn (DsaRate $r) => $r->update(['effective_to' => $from->copy()->subDay()]));

            DsaRate::create([
                'job_grade' => $grade,
                'amount' => Money::fromCents(Money::toCents($this->rateAmount)),
                'effective_from' => $from,
                'recorded_by' => Auth::id(),
            ]);
        });

        $this->reset(['rateGrade', 'rateAmount']);
        $this->toast('Rate added. Any earlier open rate for the same grade now ends the day before.');
    }

    public function saveSettings(AppSettings $appSettings): void
    {
        $this->guard();
        $rules = collect(AppSettings::DEFINITIONS)->mapWithKeys(fn ($def, $key) => ["settings.{$key}" => match (true) {
            in_array($key, AppSettings::SWITCHES, true) => ['required', 'in:0,1'],
            is_int($def[1]) => ['required', 'integer', 'min:0', 'max:1000'],
            default => ['required', 'in:nights,days'],
        }])->all();
        $this->validate($rules);

        foreach (array_keys(AppSettings::DEFINITIONS) as $key) {
            $appSettings->set($key, $this->settings[$key]);
        }

        $this->toast('Settings saved.');
    }

    /** @return class-string */
    private function modelFor(string $tab): string
    {
        return match ($tab) {
            'categories' => ActivityCategory::class,
            'types' => ActivityType::class,
            default => self::LOOKUPS[$tab][0] ?? abort(404),
        };
    }

    public function render(): View
    {
        $data = [
            'tabs' => [
                'departments' => ['icon' => 'fa-building', 'label' => 'Departments'],
                'designations' => ['icon' => 'fa-user-tag', 'label' => 'Designations'],
                'offices' => ['icon' => 'fa-location-dot', 'label' => 'Duty stations'],
                'categories' => ['icon' => 'fa-shapes', 'label' => 'Categories'],
                'types' => ['icon' => 'fa-tags', 'label' => 'Activity types'],
                'costs' => ['icon' => 'fa-coins', 'label' => 'Cost types'],
                'rates' => ['icon' => 'fa-money-bill-wave', 'label' => 'DSA rates'],
                'thresholds' => ['icon' => 'fa-sliders', 'label' => 'Thresholds'],
            ],
        ];

        // An old link to a removed tab (such as DSA destinations) opens the first one.
        if (! isset($data['tabs'][$this->tab])) {
            $this->tab = 'departments';
        }

        $data += match ($this->tab) {
            'departments', 'designations', 'offices' => ['items' => $this->modelFor($this->tab)::query()->orderBy('is_active', 'desc')->orderBy('name')->get(), 'lookupLabel' => self::LOOKUPS[$this->tab][1]],
            'categories' => ['items' => ActivityCategory::query()->withCount('types')->orderBy('name')->get()],
            'types' => ['items' => ActivityType::query()->with('category')->get()->sortBy(fn ($t) => $t->category?->name.$t->name), 'categories' => ActivityCategory::query()->orderBy('name')->get()],
            'costs' => ['items' => CostCategory::query()->ordered()->get()],
            'rates' => [
                'items' => DsaRate::query()->standard()->get()->sortBy([fn ($a, $b) => strnatcmp($a->job_grade, $b->job_grade), fn ($a, $b) => $b->effective_from <=> $a->effective_from])->values(),
                'retired' => DsaRate::query()->whereNotNull('destination_category')->orderBy('job_grade')->orderBy('destination_category')->get(),
                'grades' => DsaRate::grades(),
            ],
            default => [],
        };

        $data['mergeOptions'] = $this->showMerge && isset(self::LOOKUPS[$this->tab])
            ? self::LOOKUPS[$this->tab][0]::query()->where('is_active', true)->whereKeyNot($this->mergeFrom)->orderBy('name')->get(['id', 'name'])
            : collect();
        $data['regions'] = Region::query()->count();

        return view('livewire.settings.reference', $data);
    }
}
