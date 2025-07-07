<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Administration\Controller;

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
class BundleController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $bundleRepository,
        private readonly EntityRepository $bundleProductRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    #[Route(path: '/api/digipercep-bundle', name: 'api.digipercep-bundle.list', methods: ['GET'])]
    public function list(Request $request, Context $context): JsonResponse
    {
        try {
            $criteria = $this->buildListCriteria($request);
            $result = $this->bundleRepository->search($criteria, $context);

            $bundles = [];
            foreach ($result->getEntities() as $bundle) {
                $bundles[] = $this->serializeBundle($bundle);
            }

            return new JsonResponse([
                'data' => $bundles,
                'total' => $result->getTotal()
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_LIST_ERROR', 'Bundle list error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{id}', name: 'api.digipercep-bundle.detail', methods: ['GET'])]
    public function detail(string $id, Context $context): JsonResponse
    {
        try {
            $criteria = new Criteria([$id]);
            $criteria->addAssociation('bundleProducts.product.cover');
            $criteria->addAssociation('bundleProducts.product.prices');
            $criteria->addAssociation('bundleProducts.product.tax');
            $criteria->addAssociation('salesChannels');

            $bundle = $this->bundleRepository->search($criteria, $context)->first();

            if (!$bundle) {
                return $this->createErrorResponse('BUNDLE_NOT_FOUND', 'Bundle not found', "Bundle with id {$id} not found", 404);
            }

            return new JsonResponse(['data' => $this->serializeBundleDetails($bundle)]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_DETAIL_ERROR', 'Bundle detail error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle', name: 'api.digipercep-bundle.create', methods: ['POST'])]
    public function create(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            if ($error = $this->validateBundleData($data)) {
                return $error;
            }

            $cleanData = $this->sanitizeBundleData($data);
            $result = $this->bundleRepository->create([$cleanData], $context);
            $bundleId = $result->getPrimaryKeys('digipercep_bundle')[0];

            return new JsonResponse([
                'success' => true,
                'data' => ['id' => $bundleId],
                'message' => 'Bundle created successfully'
            ], 201);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_CREATE_ERROR', 'Bundle create error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{id}', name: 'api.digipercep-bundle.update', methods: ['PATCH'])]
    public function update(string $id, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            $bundle = $this->bundleRepository->search(new Criteria([$id]), $context)->first();
            if (!$bundle) {
                return $this->createErrorResponse('BUNDLE_NOT_FOUND', 'Bundle not found', "Bundle with id {$id} not found", 404);
            }

            if ($error = $this->validateUpdateData($data)) {
                return $error;
            }

            $updateData = $this->prepareUpdateData($id, $data);
            $this->bundleRepository->update([$updateData], $context);

            $criteria = new Criteria([$id]);
            $updatedBundle = $this->bundleRepository->search($criteria, $context)->first();

            return new JsonResponse([
                'success' => true,
                'message' => 'Bundle updated successfully',
                'data' => $this->serializeBundle($updatedBundle)
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_UPDATE_ERROR', 'Bundle update error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{id}', name: 'api.digipercep-bundle.delete', methods: ['DELETE'])]
    public function delete(string $id, Context $context): JsonResponse
    {
        try {
            $bundle = $this->bundleRepository->search(new Criteria([$id]), $context)->first();
            if (!$bundle) {
                return $this->createErrorResponse('BUNDLE_NOT_FOUND', 'Bundle not found', "Bundle with id {$id} not found", 404);
            }

            $this->bundleRepository->delete([['id' => $id]], $context);

            return new JsonResponse([
                'success' => true,
                'message' => 'Bundle deleted successfully'
            ], 204);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_DELETE_ERROR', 'Bundle delete error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{bundleId}/products', name: 'api.digipercep-bundle.products.list', methods: ['GET'])]
    public function getBundleProducts(string $bundleId, Request $request, Context $context): JsonResponse
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('bundleId', $bundleId));
            $criteria->addAssociation('product.cover');
            $criteria->addAssociation('product.prices');
            $criteria->addAssociation('product.tax');
            $criteria->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));

            // Add pagination
            $page = max(1, (int) $request->get('page', 1));
            $limit = min(100, max(1, (int) $request->get('limit', 25)));
            $criteria->setOffset(($page - 1) * $limit);
            $criteria->setLimit($limit);

            $result = $this->bundleProductRepository->search($criteria, $context);

            $products = [];
            foreach ($result->getEntities() as $bundleProduct) {
                $products[] = $this->serializeBundleProduct($bundleProduct);
            }

            return new JsonResponse([
                'data' => $products,
                'total' => $result->getTotal()
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_PRODUCTS_ERROR', 'Bundle products error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{bundleId}/products', name: 'api.digipercep-bundle.products.add', methods: ['POST'])]
    public function addProductToBundle(string $bundleId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            if (empty($data['productId'])) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Product ID is required', 400);
            }

            // Verify bundle exists
            $bundle = $this->bundleRepository->search(new Criteria([$bundleId]), $context)->first();
            if (!$bundle) {
                return $this->createErrorResponse('BUNDLE_NOT_FOUND', 'Bundle not found', "Bundle with id {$bundleId} not found", 404);
            }

            // Verify product exists
            $product = $this->productRepository->search(new Criteria([$data['productId']]), $context)->first();
            if (!$product) {
                return $this->createErrorResponse('PRODUCT_NOT_FOUND', 'Product not found', "Product with id {$data['productId']} not found", 404);
            }

            // Check if product already exists in bundle
            $criteria = new Criteria();
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('bundleId', $bundleId),
                new EqualsFilter('productId', $data['productId'])
            ]));

            $existing = $this->bundleProductRepository->search($criteria, $context)->first();
            if ($existing) {
                return $this->createErrorResponse('DUPLICATE_PRODUCT', 'Product already in bundle', 'This product is already in the bundle', 400);
            }

            // Get next position
            $nextPosition = $this->getNextPosition($bundleId, $context);

            $bundleProductData = [
                'bundleId' => $bundleId,
                'productId' => $data['productId'],
                'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
                'position' => (int) ($data['position'] ?? $nextPosition),
                'isOptional' => (bool) ($data['isOptional'] ?? false)
            ];

            $result = $this->bundleProductRepository->create([$bundleProductData], $context);
            $bundleProductId = $result->getPrimaryKeys('digipercep_bundle_product')[0];

            return new JsonResponse([
                'success' => true,
                'data' => ['id' => $bundleProductId],
                'message' => 'Product added to bundle successfully'
            ], 201);
        } catch (\Exception $e) {
            return $this->createErrorResponse('ADD_PRODUCT_ERROR', 'Add product error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/products/{bundleProductId}', name: 'api.digipercep-bundle.products.update', methods: ['PATCH'])]
    public function updateBundleProduct(string $bundleProductId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            $bundleProduct = $this->bundleProductRepository->search(new Criteria([$bundleProductId]), $context)->first();
            if (!$bundleProduct) {
                return $this->createErrorResponse('BUNDLE_PRODUCT_NOT_FOUND', 'Bundle product not found', "Bundle product with id {$bundleProductId} not found", 404);
            }

            $updateData = ['id' => $bundleProductId];

            if (isset($data['quantity'])) {
                $updateData['quantity'] = max(1, (int) $data['quantity']);
            }
            if (isset($data['position'])) {
                $updateData['position'] = max(0, (int) $data['position']);
            }
            if (isset($data['isOptional'])) {
                $updateData['isOptional'] = (bool) $data['isOptional'];
            }

            $this->bundleProductRepository->update([$updateData], $context);

            return new JsonResponse([
                'success' => true,
                'message' => 'Bundle product updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('UPDATE_BUNDLE_PRODUCT_ERROR', 'Update bundle product error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/products/{bundleProductId}', name: 'api.digipercep-bundle.products.remove', methods: ['DELETE'])]
    public function removeProductFromBundle(string $bundleProductId, Context $context): JsonResponse
    {
        try {
            $bundleProduct = $this->bundleProductRepository->search(new Criteria([$bundleProductId]), $context)->first();
            if (!$bundleProduct) {
                return $this->createErrorResponse('BUNDLE_PRODUCT_NOT_FOUND', 'Bundle product not found', "Bundle product with id {$bundleProductId} not found", 404);
            }

            $this->bundleProductRepository->delete([['id' => $bundleProductId]], $context);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product removed from bundle successfully'
            ], 204);
        } catch (\Exception $e) {
            return $this->createErrorResponse('REMOVE_PRODUCT_ERROR', 'Remove product error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{bundleId}/products/bulk', name: 'api.digipercep-bundle.products.bulk-add', methods: ['POST'])]
    public function bulkAddProductsToBundle(string $bundleId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            if (empty($data['products']) || !is_array($data['products'])) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Products array is required', 400);
            }

            // Verify bundle exists
            $bundle = $this->bundleRepository->search(new Criteria([$bundleId]), $context)->first();
            if (!$bundle) {
                return $this->createErrorResponse('BUNDLE_NOT_FOUND', 'Bundle not found', "Bundle with id {$bundleId} not found", 404);
            }

            $bundleProductsToCreate = [];
            $nextPosition = $this->getNextPosition($bundleId, $context);
            $errors = [];
            $successCount = 0;

            foreach ($data['products'] as $index => $productData) {
                if (empty($productData['productId'])) {
                    $errors[] = "Product at index {$index}: Product ID is required";
                    continue;
                }

                // Check if product exists
                $product = $this->productRepository->search(new Criteria([$productData['productId']]), $context)->first();
                if (!$product) {
                    $errors[] = "Product at index {$index}: Product not found";
                    continue;
                }

                // Check for duplicates
                $criteria = new Criteria();
                $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
                    new EqualsFilter('bundleId', $bundleId),
                    new EqualsFilter('productId', $productData['productId'])
                ]));

                $existing = $this->bundleProductRepository->search($criteria, $context)->first();
                if ($existing) {
                    $errors[] = "Product at index {$index}: Already exists in bundle";
                    continue;
                }

                $bundleProductsToCreate[] = [
                    'bundleId' => $bundleId,
                    'productId' => $productData['productId'],
                    'quantity' => max(1, (int) ($productData['quantity'] ?? 1)),
                    'position' => (int) ($productData['position'] ?? $nextPosition++),
                    'isOptional' => (bool) ($productData['isOptional'] ?? false)
                ];
                $successCount++;
            }

            if (!empty($bundleProductsToCreate)) {
                $this->bundleProductRepository->create($bundleProductsToCreate, $context);
            }

            $response = [
                'success' => true,
                'message' => "Bulk operation completed: {$successCount} products added",
                'data' => [
                    'created' => $successCount,
                    'errors' => $errors,
                    'totalProcessed' => count($data['products'])
                ]
            ];

            return new JsonResponse($response, 201);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BULK_ADD_PRODUCTS_ERROR', 'Bulk add products error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{bundleId}/products/reorder', name: 'api.digipercep-bundle.products.reorder', methods: ['PATCH'])]
    public function reorderBundleProducts(string $bundleId, Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->decodeRequestData($request);

            if (empty($data['productOrders']) || !is_array($data['productOrders'])) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Product orders array is required', 400);
            }

            $updateData = [];
            foreach ($data['productOrders'] as $order) {
                if (!isset($order['id']) || !isset($order['position'])) {
                    continue;
                }

                $updateData[] = [
                    'id' => $order['id'],
                    'position' => max(0, (int) $order['position'])
                ];
            }

            if (!empty($updateData)) {
                $this->bundleProductRepository->update($updateData, $context);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Bundle products reordered successfully',
                'data' => ['updated' => count($updateData)]
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('REORDER_PRODUCTS_ERROR', 'Reorder products error', $e->getMessage());
        }
    }

    #[Route(path: '/api/digipercep-bundle/{bundleId}/statistics', name: 'api.digipercep-bundle.statistics', methods: ['GET'])]
    public function getBundleStatistics(string $bundleId, Context $context): JsonResponse
    {
        try {
            $bundle = $this->bundleRepository->search(new Criteria([$bundleId]), $context)->first();
            if (!$bundle) {
                return $this->createErrorResponse('BUNDLE_NOT_FOUND', 'Bundle not found', "Bundle with id {$bundleId} not found", 404);
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('bundleId', $bundleId));
            $criteria->addAssociation('product.prices');
            $criteria->addAssociation('product.tax');

            $bundleProducts = $this->bundleProductRepository->search($criteria, $context);

            $stats = [
                'productCount' => $bundleProducts->getTotal(),
                'totalValue' => 0,
                'discountValue' => 0,
                'finalValue' => 0,
                'optionalProductCount' => 0,
                'requiredProductCount' => 0
            ];

            foreach ($bundleProducts->getEntities() as $bundleProduct) {
                if ($bundleProduct->getIsOptional()) {
                    $stats['optionalProductCount']++;
                } else {
                    $stats['requiredProductCount']++;
                }

                if ($bundleProduct->getProduct() && $bundleProduct->getProduct()->getPrice()) {
                    $productPrice = $bundleProduct->getProduct()->getPrice()->getGross();
                    $productTotal = $productPrice * $bundleProduct->getQuantity();
                    $stats['totalValue'] += $productTotal;
                }
            }

            // Calculate discount
            if ($bundle->getDiscount() && $stats['totalValue'] > 0) {
                if ($bundle->getDiscountType() === 'percentage') {
                    $stats['discountValue'] = $stats['totalValue'] * ($bundle->getDiscount() / 100);
                } else {
                    $stats['discountValue'] = min($bundle->getDiscount(), $stats['totalValue']);
                }
            }

            $stats['finalValue'] = max(0, $stats['totalValue'] - $stats['discountValue']);

            return new JsonResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return $this->createErrorResponse('BUNDLE_STATISTICS_ERROR', 'Bundle statistics error', $e->getMessage());
        }
    }

    // Private helper methods
    private function getNextPosition(string $bundleId, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('bundleId', $bundleId));
        $criteria->addSorting(new FieldSorting('position', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $lastProduct = $this->bundleProductRepository->search($criteria, $context)->first();
        return $lastProduct ? ($lastProduct->getPosition() + 1) : 1;
    }

    private function buildListCriteria(Request $request): Criteria
    {
        $page = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 25)));

        $criteria = new Criteria();
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->setLimit($limit);

        if ($term = $request->get('term')) {
            $criteria->addFilter(new ContainsFilter('name', trim($term)));
        }

        $sortBy = $request->get('sortBy', 'createdAt');
        $allowedSortFields = ['name', 'discount', 'active', 'createdAt', 'updatedAt', 'priority'];

        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'createdAt';
        }

        $sortDirection = strtoupper($request->get('sortDirection', 'DESC')) === 'ASC'
            ? FieldSorting::ASCENDING
            : FieldSorting::DESCENDING;

        $criteria->addSorting(new FieldSorting($sortBy, $sortDirection));

        return $criteria;
    }

    private function decodeRequestData(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON in request body');
        }

        return $data ?? [];
    }

    private function validateBundleData(array $data): ?JsonResponse
    {
        if (empty($data['name']) || trim($data['name']) === '') {
            return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Bundle name is required', 400);
        }

        if (isset($data['discount'])) {
            $discount = (float) $data['discount'];
            if ($discount < 0) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Discount cannot be negative', 400);
            }

            if (isset($data['discountType']) && $data['discountType'] === 'percentage' && $discount > 100) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Percentage discount cannot exceed 100%', 400);
            }
        }

        return null;
    }

    private function validateUpdateData(array $data): ?JsonResponse
    {
        if (isset($data['name']) && trim($data['name']) === '') {
            return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Bundle name cannot be empty', 400);
        }

        if (isset($data['discount'])) {
            $discount = (float) $data['discount'];
            if ($discount < 0) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Discount cannot be negative', 400);
            }

            if (isset($data['discountType']) && $data['discountType'] === 'percentage' && $discount > 100) {
                return $this->createErrorResponse('VALIDATION_ERROR', 'Validation error', 'Percentage discount cannot exceed 100%', 400);
            }
        }

        return null;
    }

    private function sanitizeBundleData(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'discount' => max(0, (float) ($data['discount'] ?? 0)),
            'discountType' => in_array($data['discountType'] ?? '', ['percentage', 'absolute']) ? $data['discountType'] : 'percentage',
            'isSelectable' => (bool) ($data['isSelectable'] ?? false),
            'active' => (bool) ($data['active'] ?? true),
            'priority' => max(0, (int) ($data['priority'] ?? 0)),
        ];
    }

    private function prepareUpdateData(string $id, array $data): array
    {
        $updateData = ['id' => $id];

        $fieldMappings = [
            'name' => fn($value) => trim($value),
            'discount' => fn($value) => max(0, (float) $value),
            'discountType' => fn($value) => in_array($value, ['percentage', 'absolute']) ? $value : 'percentage',
            'isSelectable' => fn($value) => (bool) $value,
            'active' => fn($value) => (bool) $value,
            'priority' => fn($value) => max(0, (int) $value)
        ];

        foreach ($fieldMappings as $field => $sanitizer) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $sanitizer($data[$field]);
            }
        }

        return $updateData;
    }

    private function serializeBundle($bundle): array
    {
        return [
            'id' => $bundle->getId(),
            'name' => $bundle->getName(),
            'discount' => $bundle->getDiscount(),
            'discountType' => $bundle->getDiscountType(),
            'isSelectable' => $bundle->isSelectable(),
            'active' => $bundle->getActive(),
            'priority' => $bundle->getPriority(),
            'createdAt' => $bundle->getCreatedAt()?->format('c'),
            'updatedAt' => $bundle->getUpdatedAt()?->format('c'),
        ];
    }

    private function serializeBundleDetails($bundle): array
    {
        $data = $this->serializeBundle($bundle);

        if ($bundle->getBundleProducts()) {
            $data['bundleProducts'] = [];
            foreach ($bundle->getBundleProducts() as $bundleProduct) {
                $data['bundleProducts'][] = $this->serializeBundleProduct($bundleProduct);
            }
        }

        return $data;
    }

    private function serializeBundleProduct($bundleProduct): array
    {
        $product = $bundleProduct->getProduct();

        return [
            'id' => $bundleProduct->getId(),
            'bundleId' => $bundleProduct->getBundleId(),
            'productId' => $bundleProduct->getProductId(),
            'quantity' => $bundleProduct->getQuantity(),
            'position' => $bundleProduct->getPosition(),
            'isOptional' => $bundleProduct->getIsOptional(),
            'product' => $product ? [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'productNumber' => $product->getProductNumber(),
                'price' => $product->getPrice(),
                'cover' => $product->getCover(),
                'tax' => $product->getTax(),
                'active' => $product->getActive(),
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
