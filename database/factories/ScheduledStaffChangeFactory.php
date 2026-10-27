<?php

namespace Database\Factories;

use App\Models\ScheduledStaffChange;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledStaffChange>
 */
class ScheduledStaffChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'effective_on' => today()->addWeek(),
            'payload' => ['department_id' => null, 'access' => 'keep', 'replace_head' => false, 'reason' => 'Board resolution', 'reference' => null],
            'status' => ScheduledStaffChange::SCHEDULED,
        ];
    }
}
