<?php
declare(strict_types=1);

require_once __DIR__ . '/membership.php';

const PRICING_DISCOUNT_NONE = 'none';
const PRICING_DISCOUNT_MEMBER_RATE = 'member_rate';
const PRICING_DISCOUNT_SENIOR_PWD = 'senior_pwd';

function pricingDiscountOptions(): array
{
    return [
        PRICING_DISCOUNT_NONE,
        PRICING_DISCOUNT_MEMBER_RATE,
        PRICING_DISCOUNT_SENIOR_PWD,
    ];
}

function normalizePricingDiscountType(?string $value): string
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, pricingDiscountOptions(), true)
        ? $normalized
        : PRICING_DISCOUNT_NONE;
}

function pricingMemberRate(?float $memberPrice): ?float
{
    if ($memberPrice === null) {
        return null;
    }

    $normalized = round($memberPrice, 2);
    return $normalized > 0 ? $normalized : null;
}

function pricingRoleIsMember(?string $role): bool
{
    return membershipLegacyRoleIsMember($role);
}

function pricingProcessingFeeForRole(?string $role): float
{
    return pricingRoleIsMember($role) ? 7.00 : 15.00;
}

function pricingUserIsMember(?array $user): bool
{
    return is_array($user) && membershipIsActive($user);
}

function pricingProcessingFeeForUser(?array $user): float
{
    return pricingUserIsMember($user) ? 7.00 : 15.00;
}

function pricingDiscountLabel(string $discountType): string
{
    return match ($discountType) {
        PRICING_DISCOUNT_MEMBER_RATE => 'Member rate',
        PRICING_DISCOUNT_SENIOR_PWD => 'Senior/PWD 20%',
        default => '',
    };
}

function computeReservationPricing(array $court, int $hours, array $options = []): array
{
    $hoursPlayed = max(0, $hours);
    $regularRate = round((float) ($court['price'] ?? 0), 2);
    $memberRate = pricingMemberRate(isset($court['member_price']) ? (float) $court['member_price'] : null);
    $processingFee = round(max(0, (float) ($options['processing_fee'] ?? 0)), 2);
    $requestedDiscountType = normalizePricingDiscountType((string) ($options['discount_type'] ?? PRICING_DISCOUNT_NONE));
    $allowMemberRate = (bool) ($options['allow_member_rate'] ?? false);
    $autoMemberRate = (bool) ($options['auto_member_rate'] ?? false);

    if ($requestedDiscountType === PRICING_DISCOUNT_NONE && $autoMemberRate && $allowMemberRate) {
        $requestedDiscountType = PRICING_DISCOUNT_MEMBER_RATE;
    }

    $subtotal = round($regularRate * $hoursPlayed, 2);
    $discountType = PRICING_DISCOUNT_NONE;
    $discountLabel = '';
    $discountAmount = 0.00;
    $appliedRate = $regularRate;

    if ($subtotal > 0) {
        switch ($requestedDiscountType) {
            case PRICING_DISCOUNT_MEMBER_RATE:
                if ($allowMemberRate && $memberRate !== null && $memberRate < $regularRate) {
                    $discountType = PRICING_DISCOUNT_MEMBER_RATE;
                    $discountLabel = pricingDiscountLabel($discountType);
                    $appliedRate = $memberRate;
                    $discountAmount = round(max(0, ($regularRate - $memberRate) * $hoursPlayed), 2);
                }
                break;

            case PRICING_DISCOUNT_SENIOR_PWD:
                $discountType = PRICING_DISCOUNT_SENIOR_PWD;
                $discountLabel = pricingDiscountLabel($discountType);
                $discountAmount = round($subtotal * 0.20, 2);
                break;
        }
    }

    $discountAmount = min($discountAmount, $subtotal);
    $total = round(max(0, $subtotal - $discountAmount + $processingFee), 2);

    return [
        'hours_played' => $hoursPlayed,
        'regular_rate' => $regularRate,
        'member_rate' => $memberRate,
        'applied_rate' => $appliedRate,
        'subtotal' => $subtotal,
        'discount_type' => $discountType,
        'discount_label' => $discountLabel,
        'discount_amount' => $discountAmount,
        'processing_fee' => $processingFee,
        'total' => $total,
    ];
}
