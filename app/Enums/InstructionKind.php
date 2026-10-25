<?php

namespace App\Enums;

/** What the CEO instructs after approving a memo, and which department acts on it (App\Services\ActionInstructions). */
enum InstructionKind: string
{
    case Imprest = 'imprest';
    case Tickets = 'tickets';

    public function label(): string
    {
        return match ($this) {
            self::Imprest => 'Process the imprest',
            self::Tickets => 'Procure the travel tickets',
        };
    }

    /** The office that acts, as people say it. */
    public function office(): string
    {
        return match ($this) {
            self::Imprest => 'Finance',
            self::Tickets => 'Procurement',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Imprest => 'fa-money-check-dollar',
            self::Tickets => 'fa-plane-departure',
        };
    }

    /** Settings › Thresholds key holding the department; 0 means the default department below. */
    public function settingKey(): string
    {
        return match ($this) {
            self::Imprest => 'finance_department_id',
            self::Tickets => 'procurement_department_id',
        };
    }

    public function defaultDepartment(): string
    {
        return match ($this) {
            self::Imprest => 'Financial Services - Accounts',
            self::Tickets => 'Supply Chain',
        };
    }

    /**
     * The cost categories the department sees on the instruction sheet: null for every line.
     *
     * @return list<string>|null
     */
    public function costCodes(): ?array
    {
        return match ($this) {
            self::Imprest => null,
            self::Tickets => ['travel', 'vehicle_hire'],
        };
    }

    public function referenceLabel(): string
    {
        return match ($this) {
            self::Imprest => 'Imprest number',
            self::Tickets => 'LPO or ticket numbers',
        };
    }
}
