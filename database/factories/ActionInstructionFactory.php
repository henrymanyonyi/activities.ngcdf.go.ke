<?php

namespace Database\Factories;

use App\Enums\InstructionKind;
use App\Enums\InstructionStatus;
use App\Models\ActionInstruction;
use App\Models\Activity;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionInstruction>
 */
class ActionInstructionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'kind' => InstructionKind::Imprest,
            'department_id' => Department::factory(),
            'status' => InstructionStatus::Sent,
            'sent_at' => now(),
        ];
    }
}
