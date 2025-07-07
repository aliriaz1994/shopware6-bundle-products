<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CustomFieldBundleService
{
    // Technical name for the JSON field (for backwards compatibility)
    private const TECHNICAL_FIELD_NAME = 'digipercep_bundle_product';

    // Individual bundle slot field names
    private const BUNDLE_FIELD_NAMES = [
        'bundle_1' => 'bundle_1',
        'bundle_2' => 'bundle_2',
        'bundle_3' => 'bundle_3'
    ];

    public function __construct(
        private EntityRepository $productRepository,
        private EntityRepository $bundleRepository,
        private SalesChannelRepository $salesChannelProductRepository,
        private BundleSyncService $bundleSyncService
    ) {
    }

    /**
     * Get bundle assignments from individual custom fields
     */
    public function getBundleAssignments(string $productId, Context $context): array
    {
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $context)->first();

        if (!$product) {
            return $this->getEmptyBundleSlots();
        }

        $customFields = $product->getCustomFields() ?? [];
        $bundleSlots = $this->getEmptyBundleSlots();

        // Get individual bundle assignments
        foreach (self::BUNDLE_FIELD_NAMES as $slotName => $fieldName) {
            $bundleId = $customFields[$fieldName] ?? null;
            // Add validation to ensure bundleId is not empty
            if ($bundleId && !empty(trim($bundleId))) {
                $bundleSlots[$slotName] = [
                    'bundleId' => $bundleId,
                    'priority' => $this->getSlotPriority($slotName),
                    'assignedAt' => null // Will be loaded separately if needed
                ];
            }
        }

        return $bundleSlots;
    }

    /**
     * Get bundle assignments with full bundle details wrapped in ArrayStruct
     */
    public function getBundleAssignmentsWithDetails(string $productId, Context $context): ArrayStruct
    {
        $assignments = $this->getBundleAssignments($productId, $context);

        // Load bundle details for each assignment
        foreach ($assignments as $slotName => &$assignment) {
            if ($assignment && isset($assignment['bundleId']) && !empty($assignment['bundleId'])) {
                try {
                    $bundle = $this->loadBundleBasicInfo($assignment['bundleId'], $context);

                    if ($bundle) {
                        $assignment['bundle'] = [
                            'id' => $bundle->getId(),
                            'name' => $bundle->getName(),
                            'discount' => $bundle->getDiscount(),
                            'discountType' => $bundle->getDiscountType(),
                            'active' => $bundle->getActive()
                        ];
                    } else {
                        $assignment['bundle'] = null;
                    }
                } catch (\Exception $e) {
                    // Bundle not found or error, keep assignment without bundle details
                    $assignment['bundle'] = null;
                }
            }
        }

        // Return wrapped in Shopware's ArrayStruct
        return new ArrayStruct($assignments);
    }

    /**
     * Load bundle with associated products and proper sales channel pricing
     */
    public function loadBundleWithProducts(string $bundleId, SalesChannelContext $salesChannelContext)
    {
        // First, load the bundle from admin repository
        $criteria = new Criteria([$bundleId]);
        $criteria->addAssociation('bundleProducts');
        $criteria->addFilter(new EqualsFilter('active', true));

        $bundle = $this->bundleRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (!$bundle || !$bundle->getBundleProducts()) {
            return null;
        }

        // Extract product IDs from bundle products
        $productIds = [];
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            if ($bundleProduct->getProductId()) {
                $productIds[] = $bundleProduct->getProductId();
            }
        }

        if (empty($productIds)) {
            return $bundle;
        }

        // Load products with calculated prices using sales channel repository
        $productCriteria = new Criteria($productIds);
        $productCriteria->addFilter(new ProductAvailableFilter($salesChannelContext->getSalesChannel()->getId()));
        $productCriteria->addAssociation('cover.media');
        $productCriteria->addAssociation('media');

        // Load products from sales channel to get calculated prices
        $products = $this->salesChannelProductRepository->search(
            $productCriteria,
            $salesChannelContext
        );

        // Create a map of products by ID for easy lookup
        $productsById = [];
        foreach ($products->getEntities() as $product) {
            $productsById[$product->getId()] = $product;
        }

        // Update bundle products with the loaded product data (including calculated prices)
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            $productId = $bundleProduct->getProductId();
            if (isset($productsById[$productId])) {
                $bundleProduct->setProduct($productsById[$productId]);
            }
        }

        return $bundle;
    }

    /**
     * Load basic bundle info without products (for initial loading)
     */
    private function loadBundleBasicInfo(string $bundleId, Context $context)
    {
        $criteria = new Criteria([$bundleId]);
        $criteria->addFilter(new EqualsFilter('active', true));

        $result = $this->bundleRepository->search($criteria, $context);
        return $result->first();
    }

    /**
     * Save bundle assignments to individual custom fields
     */
    public function saveBundleAssignments(string $productId, array $bundleSlots, Context $context): void
    {
        // Get current custom fields to preserve other custom field data
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $context)->first();

        if (!$product) {
            throw new \RuntimeException("Product with ID {$productId} not found");
        }

        $currentCustomFields = $product->getCustomFields() ?? [];

        // Update individual bundle fields
        foreach (self::BUNDLE_FIELD_NAMES as $slotName => $fieldName) {
            if (isset($bundleSlots[$slotName]) && $bundleSlots[$slotName] !== null) {
                $slotData = $bundleSlots[$slotName];
                if (isset($slotData['bundleId']) && !empty(trim($slotData['bundleId']))) {
                    $currentCustomFields[$fieldName] = $slotData['bundleId'];
                } else {
                    // Clear the field if no bundle ID
                    unset($currentCustomFields[$fieldName]);
                }
            } else {
                // Clear the field if slot is null
                unset($currentCustomFields[$fieldName]);
            }
        }

        // Also save to technical field for backwards compatibility
        $technicalData = $this->prepareBundleDataForStorage($bundleSlots);
        if (!empty($technicalData)) {
            $currentCustomFields[self::TECHNICAL_FIELD_NAME] = $technicalData;
        } else {
            unset($currentCustomFields[self::TECHNICAL_FIELD_NAME]);
        }

        // Update the product
        $this->productRepository->update([
            [
                'id' => $productId,
                'customFields' => $currentCustomFields
            ]
        ], $context);
    }

    /**
     * Save individual bundle slot
     */
    public function saveBundleSlot(string $productId, string $slotName, ?string $bundleId, Context $context): void
    {
        if (!array_key_exists($slotName, self::BUNDLE_FIELD_NAMES)) {
            throw new \InvalidArgumentException("Invalid bundle slot: {$slotName}");
        }

        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $context)->first();

        if (!$product) {
            throw new \RuntimeException("Product with ID {$productId} not found");
        }

        $currentCustomFields = $product->getCustomFields() ?? [];
        $fieldName = self::BUNDLE_FIELD_NAMES[$slotName];

        if ($bundleId && !empty(trim($bundleId))) {
            $currentCustomFields[$fieldName] = $bundleId;
        } else {
            unset($currentCustomFields[$fieldName]);
        }

        // Update technical field as well
        $currentAssignments = $this->getBundleAssignments($productId, $context);
        if ($bundleId && !empty(trim($bundleId))) {
            $currentAssignments[$slotName] = [
                'bundleId' => $bundleId,
                'priority' => $this->getSlotPriority($slotName),
                'assignedAt' => date('c')
            ];
        } else {
            $currentAssignments[$slotName] = null;
        }

        $technicalData = $this->prepareBundleDataForStorage($currentAssignments);
        if (!empty($technicalData)) {
            $currentCustomFields[self::TECHNICAL_FIELD_NAME] = $technicalData;
        } else {
            unset($currentCustomFields[self::TECHNICAL_FIELD_NAME]);
        }

        $this->productRepository->update([
            [
                'id' => $productId,
                'customFields' => $currentCustomFields
            ]
        ], $context);
    }

    /**
     * Clear all bundle assignments for a product
     */
    public function clearBundleAssignments(string $productId, Context $context): void
    {
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, $context)->first();

        if (!$product) {
            return;
        }

        $currentCustomFields = $product->getCustomFields() ?? [];

        // Remove individual bundle fields
        foreach (self::BUNDLE_FIELD_NAMES as $fieldName) {
            unset($currentCustomFields[$fieldName]);
        }

        // Remove technical field
        unset($currentCustomFields[self::TECHNICAL_FIELD_NAME]);

        $this->productRepository->update([
            [
                'id' => $productId,
                'customFields' => $currentCustomFields
            ]
        ], $context);
    }

    /**
     * Get products that have bundle assignments
     */
    public function getProductsWithBundleAssignments(Context $context): array
    {
        $criteria = new Criteria();

        $result = $this->productRepository->search($criteria, $context);

        $productsWithBundles = [];
        foreach ($result->getEntities() as $product) {
            $customFields = $product->getCustomFields() ?? [];

            // Check if any bundle field is set
            $hasBundles = false;
            foreach (self::BUNDLE_FIELD_NAMES as $fieldName) {
                if (isset($customFields[$fieldName]) && !empty(trim($customFields[$fieldName]))) {
                    $hasBundles = true;
                    break;
                }
            }

            if ($hasBundles) {
                $productsWithBundles[] = [
                    'productId' => $product->getId(),
                    'productName' => $product->getName(),
                    'bundleData' => $this->getBundleAssignments($product->getId(), $context)
                ];
            }
        }

        return $productsWithBundles;
    }

    /**
     * Validate bundle slot data
     */
    public function validateBundleSlots(array $bundleSlots): array
    {
        $errors = [];
        $allowedSlots = array_keys(self::BUNDLE_FIELD_NAMES);

        foreach ($bundleSlots as $slotName => $slotData) {
            if (!in_array($slotName, $allowedSlots)) {
                $errors[] = "Invalid bundle slot: {$slotName}";
                continue;
            }

            if ($slotData !== null) {
                if (!is_array($slotData)) {
                    $errors[] = "Bundle slot {$slotName} must be an array or null";
                    continue;
                }

                if (!isset($slotData['bundleId']) || empty(trim($slotData['bundleId']))) {
                    $errors[] = "Bundle slot {$slotName} must have a valid bundleId";
                    continue;
                }

                if (isset($slotData['priority']) && !is_numeric($slotData['priority'])) {
                    $errors[] = "Bundle slot {$slotName} priority must be numeric";
                }
            }
        }

        return $errors;
    }

    /**
     * Get empty bundle slots structure
     */
    private function getEmptyBundleSlots(): array
    {
        return [
            'bundle_1' => null,
            'bundle_2' => null,
            'bundle_3' => null
        ];
    }

    /**
     * Get priority for a slot (based on slot position)
     */
    private function getSlotPriority(string $slotName): int
    {
        $priorities = [
            'bundle_1' => 1,
            'bundle_2' => 2,
            'bundle_3' => 3
        ];

        return $priorities[$slotName] ?? 0;
    }

    /**
     * Prepare bundle data for storage in technical field (backwards compatibility)
     */
    private function prepareBundleDataForStorage(array $bundleSlots): array
    {
        $storageData = [];

        foreach (['bundle_1', 'bundle_2', 'bundle_3'] as $slotName) {
            if (isset($bundleSlots[$slotName]) && $bundleSlots[$slotName] !== null) {
                $slotData = $bundleSlots[$slotName];

                if (isset($slotData['bundleId']) && !empty(trim($slotData['bundleId']))) {
                    $storageData[$slotName] = [
                        'bundleId' => $slotData['bundleId'],
                        'priority' => (int) ($slotData['priority'] ?? $this->getSlotPriority($slotName)),
                        'assignedAt' => $slotData['assignedAt'] ?? date('c')
                    ];
                }
            }
        }

        return $storageData;
    }

    /**
     * Check if a product has any bundle assignments
     */
    public function hasAnyBundleAssignments(string $productId, Context $context): bool
    {
        $assignments = $this->getBundleAssignments($productId, $context);

        foreach ($assignments as $assignment) {
            if ($assignment !== null && isset($assignment['bundleId']) && !empty(trim($assignment['bundleId']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get bundle assignment for a specific slot
     */
    public function getBundleAssignmentForSlot(string $productId, string $slotName, Context $context): ?array
    {
        $assignments = $this->getBundleAssignments($productId, $context);

        return $assignments[$slotName] ?? null;
    }

    public function syncToProductBundleEntity(string $productId, Context $context): void
    {
        $assignments = $this->getBundleAssignments($productId, $context);

        // Convert to format expected by BundleSyncService
        $bundlesForSync = [];
        foreach ($assignments as $slotName => $assignment) {
            if ($assignment && isset($assignment['bundleId'])) {
                $bundlesForSync[] = [
                    'bundleId' => $assignment['bundleId'],
                    'bundleSlot' => $slotName,
                    'priority' => $assignment['priority'],
                    'active' => true
                ];
            }
        }

        // Sync to entity table (you'll need to inject BundleSyncService)
        $this->bundleSyncService->syncProductBundles($productId, $bundlesForSync, $context);
    }
}
