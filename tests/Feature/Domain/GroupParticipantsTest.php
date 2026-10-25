<?php

use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Memos\Team;
use App\Models\County;
use App\Models\Department;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\MemoDocument;
use App\Services\ParticipantChecks;
use App\Services\ParticipantReleases;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SettingsSeeder::class);
    $this->hod = headOfDepartment(Department::query()->where('name', 'ICT')->firstOrFail());
});

it('adds officers by number without names, with DSA for each of them at their scale', function () {
    $memo = departmentMemo($this->hod, ['days' => 3]);
    $this->editor = app(ActivityEditor::class);
    $this->editor->addLocation($memo, ['county_id' => County::query()->where('name', 'Kisumu')->value('id')], $this->hod);

    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $memo])
        ->set('groupLabel', 'Fund Account Managers (FAMs)')->set('groupCount', '10')->set('groupGrade', '')
        ->call('addGroup')->assertHasErrors(['groupGrade'])
        ->set('groupGrade', '7')->call('addGroup')->assertHasNoErrors()
        ->assertSee('10 × Fund Account Managers (FAMs)');

    $group = $memo->participants()->whereNotNull('group_label')->sole();
    expect($group)->staff_id->toBeNull()->headcount->toBe(10)->job_grade->toBe('7')
        ->and($memo->costs()->where('activity_participant_id', $group->id)->where('is_computed', true)->sole())
        ->quantity->toBe('4.00')->estimated_amount->toBe('448000.00') // 4 nights × 11,200 × 10
        ->description->toBe('DSA 4 nights × 11,200.00 × 10 people')
        ->and($memo->fresh()->staff_count)->toBe(11); // the named officer and the ten

    $data = app(MemoDocument::class)->data($memo->fresh());
    expect(collect($data['detailed'])->firstWhere('category', 'Fund Account Managers (FAMs)'))->toMatchArray(['scale' => '7', 'pax' => 10, 'days' => '4', 'rate' => 11200_00, 'amount' => 448000_00])
        ->and(collect($data['itinerary'])->firstWhere('name', 'Fund Account Managers (FAMs)')['designation'])->toBe('10 officers (scale 7)')
        ->and($data['request'])->toContain('11 officers')
        ->and(app(ParticipantChecks::class)->conflictsFor($memo->fresh()))->toBe([]);
});

it('takes a group through approval and never treats it as a named officer', function () {
    $memo = departmentMemo($this->hod, ['days' => 3]);
    $group = app(ActivityEditor::class)->addGroup($memo, ['group_label' => 'FAMs', 'headcount' => 5, 'job_grade' => '9'], ParticipantRole::Member, $this->hod);
    $lifecycle = app(ActivityLifecycle::class);
    $lifecycle->submitForReview($memo->fresh(), $this->hod);
    $lifecycle->forward($memo->fresh(), assistant());
    $lifecycle->decide($memo->fresh(), DecisionType::Approved, null, ceo());

    expect(fn () => app(ParticipantReleases::class)->preview($group->fresh(), $memo->fresh()->start_date))->toThrow(ActivityWorkflowException::class, 'Only a named officer')
        ->and(fn () => app(ActivityEditor::class)->addGroup($memo->fresh(), ['group_label' => 'FAMs', 'headcount' => 0, 'job_grade' => '9'], ParticipantRole::Member, chiefOfStaff(), 'More'))->toThrow(ActivityWorkflowException::class, 'from 1 to 500');

    $this->actingAs(chiefOfStaff())->get(route('activities.show', [$memo, 'tab' => 'team']))->assertOk()->assertSee('5 × FAMs');
    $this->actingAs($this->hod)->get(route('memos.document', $memo))->assertOk();
});
