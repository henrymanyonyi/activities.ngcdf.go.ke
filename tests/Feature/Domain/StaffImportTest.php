<?php

use App\Enums\EmploymentType;
use App\Livewire\StaffList;
use App\Models\Department;
use App\Models\Staff;
use App\Services\StaffImporter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function hrCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'hr').'.csv';
    file_put_contents($path, $content);

    return $path;
}

it('imports permanent staff, casuals and attachees from the unified Excel template', function () {
    // PF Number is a text cell with a leading zero; the first two rows' dates are Excel date cells, the third's are text.
    $path = base_path('tests/Fixtures/hr-staff-list-unified.xlsx');

    $result = app(StaffImporter::class)->import($path);

    expect($result)->toBe(['created' => 3, 'updated' => 0, 'skipped' => 0, 'errors' => []]);
    expect(Staff::query()->where('name', 'Jane Akinyi Otieno')->sole())
        ->staff_number->toBe('0012345678')
        ->employment_type->toBe(EmploymentType::Permanent)
        ->contract_ends_on->toDateString()->toBe('2028-03-24')
        ->office->name->toBe('Western Region');
    expect(Staff::query()->where('name', 'Peter Kamau Njoroge')->sole())
        ->staff_number->toBeNull()
        ->employment_type->toBe(EmploymentType::Casual)
        ->job_grade->toBe('10')
        ->joined_on->toDateString()->toBe('2025-12-01')
        ->contract_ends_on->toDateString()->toBe('2026-12-14');
    expect(Staff::query()->where('name', 'Mary Wanjiru')->sole())
        ->employment_type->toBe(EmploymentType::Attachee)
        ->job_grade->toBe('INTERN')
        ->department->name->toBe('Research & Statistics');
});

it('rejects the whole file with a message for every faulty row and saves nothing', function () {
    $path = hrCsv(implode("\n", [
        'PF Number,Name,Employment Type,Department,Duty Station,Date Joined,Contract End Date',
        ',No Number,Permanent,ICT,,,',
        'PF1,,Permanent,ICT,,,',
        'PF2,Bad Type,Contract,ICT,,,',
        'PF3,Bad Department,Permanent,Finance & Planning,Mars Region,,',
        'PF4,Bad Dates,Permanent,ICT,,31/02/2026,someday',
        'PF5,Backwards,Casual,ICT,,2026-06-01,2026-01-01',
        'PF6,First,Permanent,ICT,,,',
        'pf6,Second,Permanent,ICT,,,',
        ',Same Casual,Casual,ICT,,,',
        ',same casual,Casual,ICT,,,',
        ',Fine Casual,Casual,ICT,,,',
    ])."\n");

    $result = app(StaffImporter::class)->import($path);

    expect($result['errors'])->toBe([
        'Row 2: PF Number is required for permanent staff.',
        'Row 3: Name is required.',
        'Row 4: Employment Type "Contract" is not Permanent, Casual or Attachee.',
        'Row 5: Department "Finance & Planning" is not on the list of departments. Duty Station "Mars Region" is not on the list of duty stations.',
        'Row 6: Date Joined "31/02/2026" is not a date (use YYYY-MM-DD). Contract End Date "someday" is not a date (use YYYY-MM-DD).',
        'Row 7: Contract End Date is before Date Joined.',
        'Row 9: PF Number PF6 also appears on row 8.',
        'Row 11: same casual has no PF Number and also appears on row 10.',
    ]);
    expect(Staff::query()->count())->toBe(0);
});

it('numbers rows by their line in the file when the file has blank rows', function () {
    $path = hrCsv("PF Number,Name\nPF1,One\n,\nPF2,\n");

    expect(app(StaffImporter::class)->import($path)['errors'])->toBe(['Row 4: Name is required.']);
});

it('updates a casual without a PF number by exact name and keeps their type when the column is blank', function () {
    $casual = Staff::factory()->create(['staff_number' => null, 'name' => 'Peter Kamau Njoroge', 'employment_type' => EmploymentType::Casual, 'email' => 'peter@example.com']);
    $path = hrCsv("PF Number,Name,Employment Type,Job Grade,Email,Contract End Date\n,peter kamau njoroge,,,,2027-01-31\n");

    $result = app(StaffImporter::class)->import($path);

    expect($result)->toMatchArray(['created' => 0, 'updated' => 1])
        ->and(Staff::query()->count())->toBe(1);
    expect($casual->fresh())
        ->employment_type->toBe(EmploymentType::Casual)
        ->job_grade->toBe('10')
        ->email->toBe('peter@example.com')
        ->contract_ends_on->toDateString()->toBe('2027-01-31');
});

it('rejects an officer without a PF number whose name matches two officers', function () {
    Staff::factory()->count(2)->create(['staff_number' => null, 'name' => 'John Mwangi']);
    $path = hrCsv("PF Number,Name,Employment Type\n,John Mwangi,Casual\n");

    $result = app(StaffImporter::class)->import($path);

    expect($result['errors'])->toBe(['Row 2: John Mwangi has no PF Number and matches 2 officers on the list; add the PF Number or correct the list on screen.'])
        ->and(Staff::query()->count())->toBe(2);
});

it('does not merge an officer without a PF number into a namesake who has one', function () {
    Staff::factory()->create(['staff_number' => 'PF500', 'name' => 'Juma Baraka Example']);
    $path = hrCsv("PF Number,Name,Employment Type\n,Juma Baraka Example,Attachee\n");

    $result = app(StaffImporter::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'updated' => 0])
        ->and(Staff::query()->where('name', 'Juma Baraka Example')->count())->toBe(2);
});

it('updates an officer already on the list by PF number and records them inactive', function () {
    $officer = Staff::factory()->create(['staff_number' => 'PF700', 'name' => 'Old Name', 'is_active' => true]);
    $path = hrCsv("PF Number,Name,Employment Type,Active\npf700,New Name,Permanent,No\n");

    $result = app(StaffImporter::class)->import($path);

    expect($result)->toMatchArray(['created' => 0, 'updated' => 1]);
    expect($officer->fresh())->name->toBe('New Name')->is_active->toBeFalse();
});

it('checks a file without saving anything', function () {
    $officer = Staff::factory()->create(['staff_number' => 'PF700', 'name' => 'Old Name']);
    $path = hrCsv("PF Number,Name,Employment Type,Designation\nPF700,New Name,,Brand New Title\n,New Casual,Casual,\n");

    $result = app(StaffImporter::class)->import($path, dryRun: true);

    expect($result)->toBe(['created' => 1, 'updated' => 1, 'skipped' => 0, 'errors' => []])
        ->and(Staff::query()->count())->toBe(1)
        ->and($officer->fresh()->name)->toBe('Old Name');
    $this->assertDatabaseMissing('designations', ['name' => 'Brand New Title']);
});

it('shows on screen what a checked file would do', function () {
    Livewire::actingAs(chiefOfStaff())->test(StaffList::class)
        ->set('showImport', true)
        ->set('file', UploadedFile::fake()->createWithContent('staff.csv', "PF Number,Name,Employment Type\n,New Casual,Casual\n"))
        ->call('checkImport')
        ->assertSet('importErrors', [])
        ->assertSee('The file is ready: 1 would be added and 0 updated. Nothing has been saved yet.');

    expect(Staff::query()->count())->toBe(0);
});

it('requires a PF number on screen only for permanent staff', function () {
    $ict = Department::query()->where('name', 'ICT')->value('id');

    Livewire::actingAs(chiefOfStaff())->test(StaffList::class)
        ->call('edit')
        ->set(['name' => 'Permanent Officer', 'form_department_id' => (string) $ict, 'employment_type' => 'permanent'])
        ->call('save')
        ->assertHasErrors(['staff_number' => 'required'])
        ->set(['name' => 'Casual Officer', 'employment_type' => 'casual', 'contract_ends_on' => '2026-12-31'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Staff::query()->sole())
        ->name->toBe('Casual Officer')
        ->staff_number->toBeNull()
        ->employment_type->toBe(EmploymentType::Casual)
        ->contract_ends_on->toDateString()->toBe('2026-12-31');
});

it('reads job grades as register scales and rejects ones the rate table does not know', function () {
    $path = hrCsv(implode("\n", [
        'PF Number,Name,Employment Type,Job Grade',
        'PF1,Grade Typed As Number,Permanent,6',
        'PF2,Grade As Job Group,Permanent,Job Group 10',
        'PF3,Unknown Grade,Permanent,Scale 11',
        'PF4,Permanent Intern,Permanent,intern',
        ',Casual Intern,Casual,Intern',
        ',Casual Graded,Casual,9',
    ])."\n");

    $result = app(StaffImporter::class)->import($path);

    expect($result['errors'])->toBe([
        'Row 4: Job Grade "Scale 11" is not a scale from 1 to 10, or INTERN for attachees.',
        'Row 5: Job Grade INTERN is only for attachees.',
        'Row 7: Job Grade "9": casuals are on DSA scale 10.',
    ])->and(Staff::query()->count())->toBe(0);

    $valid = hrCsv("PF Number,Name,Employment Type,Job Grade\nPF1,Grade Typed As Number,Permanent,6\nPF2,Grade As Job Group,Permanent,Job Group 10\n,Casual Intern,Casual,Intern\n,Casual Graded,Casual,10\nPF5,Not Yet Graded,Permanent,\nPF6,Old Label,Permanent,NGCDF 7\nPF7,Scale Label,Permanent,Scale 2\n");
    expect(app(StaffImporter::class)->import($valid)['errors'])->toBe([])
        ->and(Staff::query()->orderBy('id')->pluck('job_grade', 'name')->all())->toBe([
            'Grade Typed As Number' => '6',
            'Grade As Job Group' => '10',
            'Casual Intern' => '10',
            'Casual Graded' => '10',
            'Not Yet Graded' => null,
            'Old Label' => '7',
            'Scale Label' => '2',
        ]);
});

it('reads the scale from a "Job Scale" column, as in the HR register', function () {
    $path = hrCsv("PF Number,Name,Job Scale\nPF1,Register Officer,4\n");

    expect(app(StaffImporter::class)->import($path)['errors'])->toBe([])
        ->and(Staff::query()->sole()->job_grade)->toBe('4');
});

it('puts every casual on DSA scale 10, including one recorded earlier on INTERN, and keeps a reclassified attachee\'s grade', function () {
    $casual = Staff::factory()->create(['staff_number' => null, 'name' => 'Earlier Casual', 'employment_type' => EmploymentType::Casual, 'job_grade' => 'INTERN']);
    $attachee = Staff::factory()->create(['staff_number' => null, 'name' => 'Reclassified Attachee', 'employment_type' => EmploymentType::Attachee, 'job_grade' => '8']);

    app(StaffImporter::class)->import(hrCsv("PF Number,Name,Employment Type,Job Grade\n,Earlier Casual,Casual,\n,Reclassified Attachee,Attachee,\n"));

    expect($casual->fresh()->job_grade)->toBe('10')->and($attachee->fresh()->job_grade)->toBe('8');
});

it('puts a casual added on screen on scale 10 and an attachee on INTERN, and refuses INTERN for permanent staff and another scale for a casual', function () {
    $ict = Department::query()->where('name', 'ICT')->value('id');

    Livewire::actingAs(chiefOfStaff())->test(StaffList::class)
        ->call('edit')
        ->set(['staff_number' => 'PF900', 'name' => 'Permanent Officer', 'form_department_id' => (string) $ict, 'employment_type' => 'permanent', 'job_grade' => 'INTERN'])
        ->call('save')
        ->assertHasErrors(['job_grade'])
        ->set(['staff_number' => '', 'name' => 'Casual Officer', 'employment_type' => 'casual', 'job_grade' => '7'])
        ->call('save')
        ->assertHasErrors(['job_grade'])
        ->set(['job_grade' => ''])
        ->call('save')
        ->assertHasNoErrors()
        ->call('edit')
        ->set(['staff_number' => '', 'name' => 'Attachee Officer', 'form_department_id' => (string) $ict, 'employment_type' => 'attachee', 'job_grade' => ''])
        ->call('save')
        ->assertHasNoErrors();

    expect(Staff::query()->orderBy('id')->pluck('job_grade', 'name')->all())->toBe(['Casual Officer' => '10', 'Attachee Officer' => 'INTERN']);
});

it('updates only emails and mobile numbers from a contacts file, keeping a leaver inactive', function () {
    $ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $active = Staff::factory()->create(['staff_number' => 'PF10', 'name' => 'Asha Contact', 'department_id' => $ict->id, 'job_grade' => '6']);
    $leaver = Staff::factory()->create(['staff_number' => 'PF11', 'name' => 'Ben Leaver', 'department_id' => $ict->id, 'is_active' => false]);

    $result = app(StaffImporter::class)->import(hrCsv(implode("\n", [
        'PF Number,Name,Email,Phone',
        'PF10,Asha Contact,Asha.Contact@NGCDF.go.ke,0712 345 678',
        'PF11,Ben Leaver,,712000999',
    ])."\n"));

    expect($result)->toBe(['created' => 0, 'updated' => 2, 'skipped' => 0, 'errors' => []]);
    expect($active->fresh())->email->toBe('asha.contact@ngcdf.go.ke')->phone->toBe('+254712345678')->job_grade->toBe('6')->department_id->toBe($ict->id)->is_active->toBeTrue();
    expect($leaver->fresh())->phone->toBe('+254712000999')->is_active->toBeFalse();
});

it('rejects a phone that is not a Kenyan mobile number', function () {
    $result = app(StaffImporter::class)->import(hrCsv("PF Number,Name,Department,Phone\nPF12,Office Line,ICT,020 123 4567\n"));

    expect($result['errors'])->toBe(['Row 2: Phone "020 123 4567" is not a Kenyan mobile number (e.g. 0712 345 678 or +254 712 345 678).'])
        ->and(Staff::query()->count())->toBe(0);
});
