<?php
declare(strict_types=1);

const MEMBERSHIP_STATUS_INACTIVE = 'inactive';
const MEMBERSHIP_STATUS_ACTIVE = 'active';
const MEMBERSHIP_STATUS_EXPIRED = 'expired';
const MEMBERSHIP_STATUS_SUSPENDED = 'suspended';

function membershipAllStatuses(): array
{
    return [
        MEMBERSHIP_STATUS_INACTIVE,
        MEMBERSHIP_STATUS_ACTIVE,
        MEMBERSHIP_STATUS_EXPIRED,
        MEMBERSHIP_STATUS_SUSPENDED,
    ];
}

function membershipEditableStatuses(): array
{
    return membershipAllStatuses();
}

function membershipNormalizeStatus(?string $value): string
{
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, membershipAllStatuses(), true)
        ? $normalized
        : MEMBERSHIP_STATUS_INACTIVE;
}

function membershipStatusLabel(string $status): string
{
    return match (membershipNormalizeStatus($status)) {
        MEMBERSHIP_STATUS_ACTIVE => 'Active',
        MEMBERSHIP_STATUS_EXPIRED => 'Expired',
        MEMBERSHIP_STATUS_SUSPENDED => 'Suspended',
        default => 'Inactive',
    };
}

function membershipStatusBadgeClass(string $status): string
{
    return match (membershipNormalizeStatus($status)) {
        MEMBERSHIP_STATUS_ACTIVE => 'bg-emerald-100 text-emerald-700',
        MEMBERSHIP_STATUS_EXPIRED => 'bg-amber-100 text-amber-700',
        MEMBERSHIP_STATUS_SUSPENDED => 'bg-rose-100 text-rose-700',
        default => 'bg-slate-100 text-slate-700',
    };
}

function membershipLegacyRoleIsMember(?string $role): bool
{
    return strtolower(trim((string) $role)) === 'subscriber';
}

function membershipParseDate(?string $value): ?DateTimeImmutable
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $normalized = trim($value);
    $dateOnly = DateTimeImmutable::createFromFormat('Y-m-d', $normalized);
    if ($dateOnly instanceof DateTimeImmutable) {
        return $dateOnly;
    }

    try {
        return new DateTimeImmutable($normalized);
    } catch (Throwable) {
        return null;
    }
}

function membershipResolveStatus(array $user, ?DateTimeImmutable $reference = null): string
{
    $referenceDate = ($reference ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $storedStatus = membershipNormalizeStatus((string) ($user['membership_status'] ?? ''));
    $expiresAt = membershipParseDate((string) ($user['membership_expires_at'] ?? ''));
    $isLegacyMember = membershipLegacyRoleIsMember((string) ($user['role'] ?? ''));

    if ($storedStatus === MEMBERSHIP_STATUS_SUSPENDED) {
        return MEMBERSHIP_STATUS_SUSPENDED;
    }

    if ($storedStatus === MEMBERSHIP_STATUS_EXPIRED) {
        return MEMBERSHIP_STATUS_EXPIRED;
    }

    if ($storedStatus === MEMBERSHIP_STATUS_ACTIVE || $isLegacyMember) {
        if ($expiresAt instanceof DateTimeImmutable && $expiresAt->setTime(0, 0) < $referenceDate) {
            return MEMBERSHIP_STATUS_EXPIRED;
        }

        return MEMBERSHIP_STATUS_ACTIVE;
    }

    return MEMBERSHIP_STATUS_INACTIVE;
}

function membershipIsActive(array $user, ?DateTimeImmutable $reference = null): bool
{
    return membershipResolveStatus($user, $reference) === MEMBERSHIP_STATUS_ACTIVE;
}

function membershipPlanLabel(?string $value): string
{
    $plan = trim((string) $value);
    return $plan !== '' ? $plan : 'No plan assigned';
}

function membershipFormatDate(?string $value, string $format = 'M j, Y'): string
{
    $parsed = membershipParseDate($value);
    return $parsed instanceof DateTimeImmutable ? $parsed->format($format) : 'Not set';
}

function membershipBenefitLines(?string $value, bool $includeDefaults = false): array
{
    $lines = array_values(
        array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', (string) $value) ?: []),
            static fn(string $line): bool => $line !== ''
        )
    );

    if ($lines !== [] || !$includeDefaults) {
        return $lines;
    }

    return [
        'Member court rates on eligible courts.',
        'Reduced online processing fee during reservations.',
    ];
}

function membershipStatusOptions(): array
{
    return [
        MEMBERSHIP_STATUS_ACTIVE => 'Active',
        MEMBERSHIP_STATUS_INACTIVE => 'Inactive',
        MEMBERSHIP_STATUS_EXPIRED => 'Expired',
        MEMBERSHIP_STATUS_SUSPENDED => 'Suspended',
    ];
}

function membershipFetchUser(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT
            id,
            name,
            full_name,
            email,
            contact_number,
            role,
            membership_status,
            membership_plan,
            member_since,
            membership_expires_at,
            membership_benefits
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $statement->execute([$userId]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($user) ? $user : null;
}
