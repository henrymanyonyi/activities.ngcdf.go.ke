<?php

namespace App\Enums;

/**
 * What the CEO instructs after approving a memo, and which department acts on it (App\Services\ActionInstructions):
 * Finance to process the imprest, Procurement to procure the tickets, or any other department to act as the CEO
 * writes in the instruction (26 October 2026).
 */
enum InstructionKind: string
{
    case Imprest = 'imprest';
    case Tickets = 'tickets';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Imprest => 'Process the imprest',
            self::Tickets => 'Procure the travel tickets',
            self::Other => 'Act on the CEO\'s instruction',
        };
    }

    /** The office that acts, as people say it. */
    public function office(): string
    {
        return match ($this) {
            self::Imprest => 'Finance',
            self::Tickets => 'Procurement',
            self::Other => 'The department',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Imprest => 'fa-money-check-dollar',
            self::Tickets => 'fa-plane-departure',
            self::Other => 'fa-share-from-square',
        };
    }

    /** Settings › Thresholds key holding the department (0 means the default below); null: the CEO chooses it. */
    public function settingKey(): ?string
    {
        return match ($this) {
            self::Imprest => 'finance_department_id',
            self::Tickets => 'procurement_department_id',
            self::Other => null,
        };
    }

    public function defaultDepartment(): ?string
    {
        return match ($this) {
            self::Imprest => 'Financial Services - Accounts',
            self::Tickets => 'Supply Chain',
            self::Other => null,
        };
    }

    /** The CEO writes what the department must do; for Finance and Procurement the task is fixed and the note optional. */
    public function needsWrittenInstruction(): bool
    {
        return $this === self::Other;
    }

    /**
     * The cost categories the department sees on the instruction sheet: null for every line, [] for none.
     *
     * @return list<string>|null
     */
    public function costCodes(): ?array
    {
        return match ($this) {
            self::Imprest => null,
            self::Tickets => ['travel', 'vehicle_hire'],
            self::Other => [],
        };
    }

    public function referenceLabel(): string
    {
        return match ($this) {
            self::Imprest => 'Imprest number',
            self::Tickets => 'LPO or ticket numbers',
            self::Other => 'Reference or what was done',
        };
    }
}
