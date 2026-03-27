<?php
declare(strict_types=1);

const GAME_STATUS_RESERVED = 'reserved';
const GAME_STATUS_CHECKED_IN = 'checked_in';
const GAME_STATUS_IN_PROGRESS = 'in_progress';
const GAME_STATUS_COMPLETED = 'completed';

function gameStatusMetadata(): array
{
    return [
        GAME_STATUS_RESERVED => [
            'label' => 'Reserved',
            'badge_class' => 'bg-slate-100 text-slate-700',
        ],
        GAME_STATUS_CHECKED_IN => [
            'label' => 'Checked-in',
            'badge_class' => 'bg-blue-100 text-blue-700',
        ],
        GAME_STATUS_IN_PROGRESS => [
            'label' => 'In Progress',
            'badge_class' => 'bg-amber-100 text-amber-700',
        ],
        GAME_STATUS_COMPLETED => [
            'label' => 'Completed',
            'badge_class' => 'bg-emerald-100 text-emerald-700',
        ],
    ];
}

function normalizeGameStatus(string $value): string
{
    $normalized = strtolower(trim($value));
    $statuses = gameStatusMetadata();

    return array_key_exists($normalized, $statuses) ? $normalized : GAME_STATUS_RESERVED;
}

function gameStatusLabel(string $value): string
{
    $status = normalizeGameStatus($value);
    $statuses = gameStatusMetadata();

    return (string) $statuses[$status]['label'];
}

function gameStatusBadgeClass(string $value): string
{
    $status = normalizeGameStatus($value);
    $statuses = gameStatusMetadata();

    return (string) $statuses[$status]['badge_class'];
}

function gameStatusIsActive(string $value): bool
{
    $status = normalizeGameStatus($value);
    return in_array($status, [GAME_STATUS_CHECKED_IN, GAME_STATUS_IN_PROGRESS], true);
}

function gameStatusSelectOptions(): array
{
    $statuses = gameStatusMetadata();

    return [
        GAME_STATUS_RESERVED => (string) $statuses[GAME_STATUS_RESERVED]['label'],
        GAME_STATUS_CHECKED_IN => (string) $statuses[GAME_STATUS_CHECKED_IN]['label'],
        GAME_STATUS_IN_PROGRESS => (string) $statuses[GAME_STATUS_IN_PROGRESS]['label'],
        GAME_STATUS_COMPLETED => (string) $statuses[GAME_STATUS_COMPLETED]['label'],
    ];
}

function gameStatusNextAction(string $value): ?array
{
    return match (normalizeGameStatus($value)) {
        GAME_STATUS_RESERVED => [
            'status' => GAME_STATUS_CHECKED_IN,
            'label' => 'Check In',
            'icon' => 'fa-right-to-bracket',
        ],
        GAME_STATUS_CHECKED_IN => [
            'status' => GAME_STATUS_IN_PROGRESS,
            'label' => 'Start Game',
            'icon' => 'fa-play',
        ],
        GAME_STATUS_IN_PROGRESS => [
            'status' => GAME_STATUS_COMPLETED,
            'label' => 'Complete',
            'icon' => 'fa-flag-checkered',
        ],
        default => null,
    };
}
