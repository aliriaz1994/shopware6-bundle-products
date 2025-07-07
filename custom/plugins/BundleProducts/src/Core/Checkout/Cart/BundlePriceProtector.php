<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

/**
 * Simple processor that protects bundle line item prices from being overridden
 */
class BundlePriceProtector implements CartProcessorInterface
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getLineItems() as $lineItem) {
            if ($lineItem->getPayloadValue('bundleLineItem') === true) {
                $this->protectBundlePrice($lineItem);
            }
        }
    }

    private function protectBundlePrice(LineItem $lineItem): void
    {
        $bundlePriceOverride = $lineItem->getPayloadValue('bundlePriceOverride');

        if ($bundlePriceOverride && is_numeric($bundlePriceOverride)) {
            $currentPrice = $lineItem->getPrice();

            // ALWAYS restore bundle price if it doesn't match exactly
            if (!$currentPrice || abs($currentPrice->getTotalPrice() - $bundlePriceOverride) > 0.01) {
                $this->logger->info('Protecting bundle price from override', [
                    'lineItemId' => $lineItem->getId(),
                    'expectedPrice' => $bundlePriceOverride,
                    'currentPrice' => $currentPrice ? $currentPrice->getTotalPrice() : 'none',
                    'priceDifference' => $currentPrice ? abs($currentPrice->getTotalPrice() - $bundlePriceOverride) : 'no current price'
                ]);

                // Force restore the exact bundle price
                $bundlePrice = new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    $bundlePriceOverride,
                    $bundlePriceOverride,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                    1
                );

                $lineItem->setPrice($bundlePrice);

                // Set absolute price definition to prevent any further changes
                $priceDefinition = new AbsolutePriceDefinition($bundlePriceOverride);
                $lineItem->setPriceDefinition($priceDefinition);

                // Add protection flags
                $lineItem->setPayloadValue('priceProtected', true);
            }
        }
    }
}
