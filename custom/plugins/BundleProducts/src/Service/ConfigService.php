<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService
{
    private const string CONFIG_DOMAIN = 'DigiPercepBundleProducts.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            self::CONFIG_DOMAIN . 'enabled',
            $salesChannelId
        );
    }

    public function showBadgeInListing(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            self::CONFIG_DOMAIN . 'showBadgeInListing',
            $salesChannelId
        );
    }

    public function getBadgeText(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(
            self::CONFIG_DOMAIN . 'badgeText',
            $salesChannelId
        ) ?: 'Bundle available';
    }

    public function getMaxBundlesPerProduct(?string $salesChannelId = null): int
    {
        return $this->systemConfigService->getInt(
            self::CONFIG_DOMAIN . 'maxBundlesPerProduct',
            $salesChannelId
        ) ?: 3;
    }

    public function showOriginalPrice(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            self::CONFIG_DOMAIN . 'showOriginalPrice',
            $salesChannelId
        );
    }

    public function showSavingsAmount(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            self::CONFIG_DOMAIN . 'showSavingsAmount',
            $salesChannelId
        );
    }

    public function flattenProductRelations(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            self::CONFIG_DOMAIN . 'flattenProductRelations',
            $salesChannelId
        );
    }
}
