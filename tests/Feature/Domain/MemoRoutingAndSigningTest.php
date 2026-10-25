<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Memos\Show as MemoShow;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Staff;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use App\Services\MemoDocument;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->officer = Staff::factory()->create(['name' => 'John Doe', 'department_id' => $this->ict->id, 'designation_id' => Designation::query()->where('name', 'Manager')->value('id')]);
    $this->hod = headOfDepartment($this->ict);
    $this->hod->update(['name' => 'John Doe', 'staff_id' => $this->officer->id]);
    $this->ceo = ceo();
    $this->acos = assistant();
    $this->cos = chiefOfStaff();
});

/** @return array<string, array<string, mixed>> the memo's signature blocks by label */
function signaturesOf(Activity $memo): array
{
    return collect(app(MemoDocument::class)->data($memo->fresh())['signatures'])->keyBy('label')->all();
}

it('takes a Head of Department\'s memo to the CEO only through the Assistant or the Chief of Staff', function () {
    $memo = departmentMemo($this->hod);

    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo])
        ->call('openSubmit')->assertDontSee('The CEO directly')
        ->set('office', 'ceo')->call('submit')->assertHasErrors(['office'])
        ->set('office', ReviewOffice::ChiefOfStaff->value)->call('submit')->assertHasNoErrors();

    expect($memo->fresh())->status->toBe(ActivityStatus::UnderReview)->review_office->toBe(ReviewOffice::ChiefOfStaff)
        ->and(fn () => $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo))->toThrow(ActivityWorkflowException::class);

    $this->lifecycle->forward($memo->fresh(), $this->cos);
    $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo);

    expect($memo->fresh()->status)->toBe(ActivityStatus::Approved)
        ->and(array_keys(signaturesOf($memo)))->toBe(['Prepared by', 'Reviewed by', 'Validated by', 'Approved by'])
        ->and(signaturesOf($memo)['Validated by']['name'])->toBe($this->cos->name);
});

it('sends a corrected memo back to the reviewer who returned it', function () {
    $memo = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);
    $this->lifecycle->returnForCorrection($memo->fresh(), 'Add the programme.', $this->acos);

    $this->lifecycle->submitForReview($memo->fresh(), $this->hod, ReviewOffice::ChiefOfStaff);
    expect($memo->fresh()->review_office)->toBe(ReviewOffice::AssistantChiefOfStaff);

    $this->lifecycle->forward($memo->fresh(), $this->acos);
    expect(signaturesOf($memo)['Reviewed by']['name'])->toBe($this->acos->name);
});

it('routes the Assistant\'s memos through the Chief of Staff whenever Settings say so', function () {
    app(AppSettings::class)->set('acos_forwards_via_cos', 1);
    $memo = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    expect($this->lifecycle->forward($memo->fresh(), $this->acos))->toBe('the Chief of Staff')
        ->and($memo->fresh())->status->toBe(ActivityStatus::UnderReview)->review_office->toBe(ReviewOffice::ChiefOfStaff);
});

it('signs with the Head\'s designation from the staff list, prefixed Ag. only when they tick acting, without changing their record', function () {
    $substantive = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($substantive, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    $acting = departmentMemo($this->hod);
    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $acting])
        ->call('openSubmit')
        ->assertSet('acting', false)->assertSee('Manager, ICT')
        ->set('acting', true)
        ->call('submit')->assertHasNoErrors();

    expect(signaturesOf($substantive)['Prepared by'])->name->toBe('John Doe')->designation->toBe('Manager, ICT')
        ->and(signaturesOf($acting)['Prepared by']['designation'])->toBe('Ag. Manager, ICT')
        ->and($acting->fresh())->hod_designation->toBe('Manager, ICT')->hod_acting->toBeTrue()
        ->and($substantive->fresh()->hod_acting)->toBeFalse()
        ->and($this->officer->fresh()->designation->name)->toBe('Manager');

    $html = view('memos.document', app(MemoDocument::class)->data($acting->fresh()))->render();
    expect($html)->toContain('<strong>John Doe</strong>')->toContain('Ag. Manager, ICT')->not->toContain('Ag. John Doe');
});

it('prints the date issued and the memo reference the Head gave', function () {
    $memo = departmentMemo($this->hod, ['source_reference' => 'NG-CDF BOARD/ICT/004/2026', 'issued_on' => '2026-10-16']);

    $html = view('memos.document', app(MemoDocument::class)->data($memo))->render();

    expect($html)->toContain('16TH OCTOBER, 2026')->toContain('NG-CDF BOARD/ICT/004/2026')->toContain('ICT Department');
});
