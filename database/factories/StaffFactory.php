<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_number' => 'PF'.fake()->unique()->numerify('#####'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'department_id' => Department::factory(),
            'job_grade' => 'NGCDF 5',
            'is_active' => true,
            'source' => 'manual',
        ];
    }
}
