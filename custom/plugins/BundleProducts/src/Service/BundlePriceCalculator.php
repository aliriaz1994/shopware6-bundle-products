<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;

class BundlePriceCalculator
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
        private readonly BundleService $bundleService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Calculates the total bundle price including all products
     */
    public function calculateBundlePrice(string $bundleId, SalesChannelContext $context): array
    {
        $bundle = $this->bundleService->getBundleById($bundleId, $context->getContext());

        if (!$bundle) {
            throw new \InvalidArgumentException('Bundle not found');
        }

        $products = [];
        $totalOriginalPrice = 0.0;
        $taxRules = new TaxRuleCollection();

        // Calculate price for bundle products (additional products)
        if ($bundle->getBundleProducts()) {
            foreach ($bundle->getBundleProducts() as $bundleProduct) {
                $product = $bundleProduct->getProduct();
                if (!$product) {
                    continue;
                }

                $quantity = $bundleProduct->getQuantity() ?: 1;
                $productPrice = $this->getProductPrice($product, $quantity, $context);

                $products[] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'quantity' => $quantity,
                    'unitPrice' => $productPrice['unitPrice'],
                    'totalPrice' => $productPrice['totalPrice'],
                    'isOptional' => $bundleProduct->isOptional(),
                    'type' => 'bundleProduct'
                ];

                if (!$bundleProduct->isOptional()) {
                    $totalOriginalPrice += $productPrice['totalPrice'];
                }

                // Collect tax rules
                if ($product->getTax()) {
                    $taxRules->add($product->getTax());
                }
            }
        }

        // Calculate price for main products
        if ($bundle->getProductBundles()) {
            foreach ($bundle->getProductBundles() as $productBundle) {
                $product = $productBundle->getProduct();
                if (!$product) {
                    continue;
                }

                $quantity = 1; // Main products typically have quantity 1
                $productPrice = $this->getProductPrice($product, $quantity, $context);

                $products[] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'quantity' => $quantity,
                    'unitPrice' => $productPrice['unitPrice'],
                    'totalPrice' => $productPrice['totalPrice'],
                    'isOptional' => false,
                    'type' => 'mainProduct'
                ];

                $totalOriginalPrice += $productPrice['totalPrice'];

                // Collect tax rules
                if ($product->getTax()) {
                    $taxRules->add($product->getTax());
                }
            }
        }

        // Calculate discount
        $discountAmount = $this->calculateDiscountAmount(
            $bundle->getDiscountType(),
            $bundle->getDiscount(),
            $totalOriginalPrice
        );

        $finalPrice = $totalOriginalPrice - $discountAmount;

        return [
            'bundleId' => $bundleId,
            'bundleName' => $bundle->getName(),
            'products' => $products,
            'originalPrice' => $totalOriginalPrice,
            'discountType' => $bundle->getDiscountType(),
            'discountValue' => $bundle->getDiscount(),
            'discountAmount' => $discountAmount,
            'finalPrice' => $finalPrice,
            'savings' => $discountAmount,
            'savingsPercentage' => $totalOriginalPrice > 0 ? ($discountAmount / $totalOriginalPrice) * 100 : 0,
            'taxRules' => $taxRules
        ];
    }

    /**
     * Calculates price for a specific product with quantity
     */
    private function getProductPrice($product, int $quantity, SalesChannelContext $context): array
    {
        $unitPrice = 0.0;

        // Get product price
        if ($product->getCalculatedPrice()) {
            $unitPrice = $product->getCalculatedPrice()->getUnitPrice();
        } elseif ($product->getPrice() && $product->getPrice()->count() > 0) {
            $firstPrice = $product->getPrice()->first();
            if ($firstPrice) {
                // Use gross price for storefront
                $unitPrice = $context->getTaxState() === SalesChannelContext::TAX_STATE_GROSS
                    ? $firstPrice->getGross()
                    : $firstPrice->getNet();
            }
        }

        return [
            'unitPrice' => $unitPrice,
            'totalPrice' => $unitPrice * $quantity
        ];
    }

    /**
     * Calculates discount amount based on type and value
     */
    private function calculateDiscountAmount(string $discountType, float $discountValue, float $totalPrice): float
    {
        if ($discountType === 'percentage') {
            return $totalPrice * ($discountValue / 100);
        }

        // Absolute discount
        return min($discountValue, $totalPrice); // Don't discount more than total price
    }

    /**
     * Calculates custom price for bundle product line items
     */
    public function calculateBundleProductPrice(
        array $lineItemData,
        SalesChannelContext $context
    ): ?array {
        $bundleId = $lineItemData['bundleId'] ?? null;
        $productId = $lineItemData['productId'] ?? null;

        if (!$bundleId || !$productId) {
            return null;
        }

        try {
            $bundle = $this->bundleService->getBundleById($bundleId, $context->getContext());
            if (!$bundle) {
                return null;
            }

            // Check if this product has special bundle pricing
            $customPrice = $this->getCustomBundleProductPrice($bundle, $productId);

            if ($customPrice !== null) {
                $quantity = $lineItemData['quantity'] ?? 1;

                $priceDefinition = new QuantityPriceDefinition(
                    $customPrice,
                    new TaxRuleCollection([]),
                    $quantity
                );

                $calculatedPrice = $this->calculator->calculate($priceDefinition, $context);

                return [
                    'priceDefinition' => $priceDefinition,
                    'calculatedPrice' => $calculatedPrice,
                    'customPrice' => $customPrice
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error('Error calculating bundle product price', [
                'bundleId' => $bundleId,
                'productId' => $productId,
                'exception' => $e
            ]);
        }

        return null;
    }

    /**
     * Gets custom price for a specific product in a bundle
     * This could be extended to support product-specific bundle pricing
     */
    private function getCustomBundleProductPrice($bundle, string $productId): ?float
    {
        // Check if the bundle has custom pricing rules for specific products
        // This is where you could implement product-specific bundle pricing

        // For now, return null to use regular product pricing
        // You could extend this to check for:
        // - Custom bundle product prices
        // - Tiered pricing based on bundle
        // - Special promotional pricing

        return null;
    }

    /**
     * Validates bundle pricing configuration
     */
    public function validateBundlePricing(string $bundleId, SalesChannelContext $context): array
    {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        try {
            $bundle = $this->bundleService->getBundleById($bundleId, $context->getContext());

            if (!$bundle) {
                $validation['valid'] = false;
                $validation['errors'][] = 'Bundle not found';
                return $validation;
            }

            // Validate discount configuration
            if ($bundle->getDiscount() < 0) {
                $validation['valid'] = false;
                $validation['errors'][] = 'Discount value cannot be negative';
            }

            if ($bundle->getDiscountType() === 'percentage' && $bundle->getDiscount() > 100) {
                $validation['valid'] = false;
                $validation['errors'][] = 'Percentage discount cannot exceed 100%';
            }

            // Validate product pricing
            $bundlePrice = $this->calculateBundlePrice($bundleId, $context);

            if ($bundlePrice['originalPrice'] <= 0) {
                $validation['warnings'][] = 'Bundle has no products with valid prices';
            }

            if ($bundlePrice['discountAmount'] >= $bundlePrice['originalPrice']) {
                $validation['warnings'][] = 'Discount amount equals or exceeds total product price';
            }

        } catch (\Exception $e) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Error validating bundle pricing: ' . $e->getMessage();

            $this->logger->error('Bundle pricing validation error', [
                'bundleId' => $bundleId,
                'exception' => $e
            ]);
        }

        return $validation;
    }
}
