<?php

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the row directly. Tests that exercise workflow should create
 * activities through ActivityEditor and move them with ActivityLifecycle.
 *
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = today()->addDays(fake()->numberBetween(14, 40));
        $days = fake()->numberBetween(1, 5);

        return [
            'title' => fake()->sentence(4),
            'purpose' => fake()->sentence(10),
            'activity_category_id' => ActivityCategory::query()->value('id') ?? ActivityCategory::create(['name' => 'Monitoring & Evaluation'])->id,
            'organising_department_id' => Department::factory(),
            'start_date' => $start,
            'days' => $days,
            'nights' => $days - 1,
            'end_date' => $start->copy()->addDays($days - 1),
        ];
    }

    public function status(ActivityStatus $status): static
    {
        return $this->afterCreating(fn (Activity $activity) => $activity->forceFill(['status' => $status])->saveQuietly());
    }
}
