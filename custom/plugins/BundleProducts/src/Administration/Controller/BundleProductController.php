<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Administration\Controller;

use DigiPercep\BundleProducts\Service\CustomFieldBundleService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BundleProductController extends AbstractController
{
    private const ALLOWED_BUNDLE_SLOTS = ['bundle_1', 'bundle_2', 'bundle_3'];

    public function __construct(
        private readonly EntityRepository $productBundleRepository,
        private readonly EntityRepository $bundleRepository,
        private readonly EntityRepository $productRepository,
        private readonly CustomFieldBundleService $customFieldBundleService
    ) {
    }

    #[Route(path: '/api/digipercep-product/{productId}/bundles', name: 'api.digipercep-product.bundles.list', methods: ['GET'])]
    public function getProductBundles(string $productId, Context $context): JsonResponse
    {
        try {
            // Get bundle assignments from both sources
            $databaseAssignments = $this->getDatabaseBundleAssignments($productId, $context);
            $customFieldAssignments = $this->customFieldBundleService->getBundleAssignments($productId, $context);

            // Merge and prioritize database assignments over custom fields
            $bundleSlots = $this->mergeBundleAssignments($databaseAssignments, $customFieldAssignments, $context);

            return new JsonResponse([
                'data' => array_values(array_filter($bundleSlots, fn($slot) => $slot !== null)),
                'bundleSlots' => $bundleSlots,
                'total' => count(array_filter($bundleSlots, fn($slot) => $slot !== null))
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('GET_PRODUCT_BUNDLES_ERROR', 'Get product bundles error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-product/{productId}/bundles/bulk', name: 'api.digipercep-product.bundles.bulk-assign', methods: ['POST'])]
    public function bulkAssignBundles(string $productId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            if (empty($data['bundleSlots']) || !is_array($data['bundleSlots'])) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Bundle slots data is required', 400);
            }

            // Validate bundle slots
            $validationErrors = $this->customFieldBundleService->validateBundleSlots($data['bundleSlots']);
            if (!empty($validationErrors)) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', implode(', ', $validationErrors), 400);
            }

            // Verify product exists
            $product = $this->productRepository->search(new Criteria([$productId]), $context)->first();
            if (!$product) {
                return $this->createErrorResponse('PRODUCT_NOT_FOUND', 'Product not found', "Product with id {$productId} not found", 404);
            }

            // Process assignments for both database and custom fields
            $databaseResults = $this->processDatabaseAssignments($productId, $data['bundleSlots'], $context);

            // Save to custom fields as backup/alternative storage
            $this->customFieldBundleService->saveBundleAssignments($productId, $data['bundleSlots'], $context);

            return new JsonResponse([
                'success' => true,
                'message' => "Bundle assignments updated successfully",
                'data' => [
                    'created' => $databaseResults['created'],
                    'updated' => $databaseResults['updated'],
                    'removed' => $databaseResults['removed'],
                    'customFieldUpdated' => true
                ]
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BULK_ASSIGN_ERROR', 'Bulk assign error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-product/{productId}/bundles/custom-field', name: 'api.digipercep-product.bundles.custom-field', methods: ['GET'])]
    public function getCustomFieldBundles(string $productId, Context $context): JsonResponse
    {
        try {
            $assignments = $this->customFieldBundleService->getBundleAssignments($productId, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $assignments
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('GET_CUSTOM_FIELD_BUNDLES_ERROR', 'Get custom field bundles error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-product/{productId}/bundles/custom-field', name: 'api.digipercep-product.bundles.custom-field-save', methods: ['POST'])]
    public function saveCustomFieldBundles(string $productId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            if (empty($data['bundleSlots'])) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Bundle slots data is required', 400);
            }

            // Validate bundle slots
            $validationErrors = $this->customFieldBundleService->validateBundleSlots($data['bundleSlots']);
            if (!empty($validationErrors)) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', implode(', ', $validationErrors), 400);
            }

            $this->customFieldBundleService->saveBundleAssignments($productId, $data['bundleSlots'], $context);

            return new JsonResponse([
                'success' => true,
                'message' => 'Custom field bundle assignments saved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('SAVE_CUSTOM_FIELD_BUNDLES_ERROR', 'Save custom field bundles error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-product/{productId}/bundles/migrate', name: 'api.digipercep-product.bundles.migrate', methods: ['POST'])]
    public function migrateBundleAssignments(string $productId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);
            $direction = $data['direction'] ?? 'database_to_custom_field'; // or 'custom_field_to_database'

            if ($direction === 'database_to_custom_field') {
                // Migrate from database to custom field
                $databaseAssignments = $this->getDatabaseBundleAssignments($productId, $context);
                $this->customFieldBundleService->saveBundleAssignments($productId, $databaseAssignments, $context);

                $message = 'Bundle assignments migrated from database to custom field';
            } else {
                // Migrate from custom field to database
                $customFieldAssignments = $this->customFieldBundleService->getBundleAssignments($productId, $context);
                $this->processDatabaseAssignments($productId, $customFieldAssignments, $context);

                $message = 'Bundle assignments migrated from custom field to database';
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('MIGRATE_BUNDLES_ERROR', 'Migrate bundles error', $e->getMessage());
        }
    }

    // Keep all your existing methods from the previous controller...
    // (getAvailableBundles, removeAssignment, etc.)

    // Private helper methods
    private function getDatabaseBundleAssignments(string $productId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $productId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('bundle');
        $criteria->addSorting(new FieldSorting('bundleSlot', FieldSorting::ASCENDING));
        $criteria->addSorting(new FieldSorting('priority', FieldSorting::ASCENDING));

        $result = $this->productBundleRepository->search($criteria, $context);

        $bundleSlots = ['bundle_1' => null, 'bundle_2' => null, 'bundle_3' => null];

        foreach ($result->getEntities() as $productBundle) {
            if ($productBundle->getBundleSlot() && array_key_exists($productBundle->getBundleSlot(), $bundleSlots)) {
                $bundleSlots[$productBundle->getBundleSlot()] = $this->serializeProductBundle($productBundle);
            }
        }

        return $bundleSlots;
    }

    private function mergeBundleAssignments(array $databaseAssignments, array $customFieldAssignments, Context $context): array
    {
        $merged = ['bundle_1' => null, 'bundle_2' => null, 'bundle_3' => null];

        foreach (['bundle_1', 'bundle_2', 'bundle_3'] as $slotName) {
            // Prioritize database assignments
            if ($databaseAssignments[$slotName] !== null) {
                $merged[$slotName] = $databaseAssignments[$slotName];
            } elseif ($customFieldAssignments[$slotName] !== null) {
                // Use custom field assignment and enrich with bundle data
                $customFieldData = $customFieldAssignments[$slotName];
                if (isset($customFieldData['bundleId'])) {
                    $bundle = $this->bundleRepository->search(new Criteria([$customFieldData['bundleId']]), $context)->first();
                    if ($bundle) {
                        $merged[$slotName] = [
                            'bundleId' => $customFieldData['bundleId'],
                            'priority' => $customFieldData['priority'] ?? 0,
                            'bundle' => [
                                'id' => $bundle->getId(),
                                'name' => $bundle->getName(),
                                'discount' => $bundle->getDiscount(),
                                'discountType' => $bundle->getDiscountType(),
                                'active' => $bundle->getActive()
                            ]
                        ];
                    }
                }
            }
        }

        return $merged;
    }

    private function processDatabaseAssignments(string $productId, array $bundleSlots, Context $context): array
    {
        $assignmentsToCreate = [];
        $assignmentsToUpdate = [];
        $assignmentsToDeactivate = [];

        foreach (self::ALLOWED_BUNDLE_SLOTS as $slotName) {
            $slotData = $bundleSlots[$slotName] ?? null;
            $existingAssignment = $this->findExistingAssignment($productId, $slotName, $context);

            if ($slotData && !empty($slotData['bundleId'])) {
                // Verify bundle exists
                $bundle = $this->bundleRepository->search(new Criteria([$slotData['bundleId']]), $context)->first();
                if (!$bundle) {
                    continue;
                }

                if ($existingAssignment) {
                    $assignmentsToUpdate[] = [
                        'id' => $existingAssignment->getId(),
                        'bundleId' => $slotData['bundleId'],
                        'priority' => (int) ($slotData['priority'] ?? 0),
                        'active' => true
                    ];
                } else {
                    $assignmentsToCreate[] = [
                        'productId' => $productId,
                        'bundleId' => $slotData['bundleId'],
                        'bundleSlot' => $slotName,
                        'priority' => (int) ($slotData['priority'] ?? 0),
                        'position' => 0,
                        'active' => true
                    ];
                }
            } else {
                if ($existingAssignment) {
                    $assignmentsToDeactivate[] = ['id' => $existingAssignment->getId()];
                }
            }
        }

        // Execute operations
        if (!empty($assignmentsToCreate)) {
            $this->productBundleRepository->create($assignmentsToCreate, $context);
        }
        if (!empty($assignmentsToUpdate)) {
            $this->productBundleRepository->update($assignmentsToUpdate, $context);
        }
        if (!empty($assignmentsToDeactivate)) {
            $this->productBundleRepository->delete($assignmentsToDeactivate, $context);
        }

        return [
            'created' => count($assignmentsToCreate),
            'updated' => count($assignmentsToUpdate),
            'removed' => count($assignmentsToDeactivate)
        ];
    }

    // Include all your existing private methods here...
    private function findExistingAssignment(string $productId, string $bundleSlot, Context $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('productId', $productId),
            new EqualsFilter('bundleSlot', $bundleSlot)
        ]));

        return $this->productBundleRepository->search($criteria, $context)->first();
    }

    private function decodeRequestData(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON in request body');
        }
        return $data ?? [];
    }

    private function serializeProductBundle($productBundle): array
    {
        $bundle = $productBundle->getBundle();
        return [
            'id' => $productBundle->getId(),
            'productId' => $productBundle->getProductId(),
            'bundleId' => $productBundle->getBundleId(),
            'bundleSlot' => $productBundle->getBundleSlot(),
            'position' => $productBundle->getPosition(),
            'priority' => $productBundle->getPriority(),
            'active' => $productBundle->getActive(),
            'bundle' => $bundle ? [
                'id' => $bundle->getId(),
                'name' => $bundle->getName(),
                'discount' => $bundle->getDiscount(),
                'discountType' => $bundle->getDiscountType(),
                'active' => $bundle->getActive()
            ] : null
        ];
    }

    private function createErrorResponse(string $code, string $title, string $detail, int $status = 500): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'errors' => [[
                'code' => "FRAMEWORK__{$code}",
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail
            ]]
        ], $status);
    }
}
