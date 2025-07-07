<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use DigiPercep\BundleProducts\Core\Content\ProductBundle\ProductBundleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class BundleSyncService
{
    private EntityRepository $productBundleRepository;

    public function __construct(
        EntityRepository $productBundleRepository
    ) {
        $this->productBundleRepository = $productBundleRepository;
    }

    public function syncProductBundles(string $productId, array $bundles, Context $context): void
    {
        try {
            // First, delete existing entries for this product
            $this->deleteExistingProductBundles($productId, $context);

            // Then, create new entries from the custom field data
            if (!empty($bundles)) {
                $this->createProductBundleEntries($productId, $bundles, $context);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf('Failed to sync bundle assignments for product %s: %s', $productId, $e->getMessage()),
                0,
                $e
            );
        }
    }

    public function getProductBundles(string $productId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $productId));
        $criteria->addAssociation('bundle');

        $productBundles = $this->productBundleRepository->search($criteria, $context);

        $result = [];
        foreach ($productBundles->getEntities() as $productBundle) {
            /** @var ProductBundleEntity $productBundle */
            $result[] = [
                'bundleId' => $productBundle->getBundleId(),
                'position' => $productBundle->getPosition(),
                'bundleSlot' => $productBundle->getBundleSlot(),
                'priority' => $productBundle->getPriority(),
                'active' => $productBundle->getActive(),
                'bundle' => $productBundle->getBundle() ? [
                    'id' => $productBundle->getBundle()->getId(),
                    'name' => $productBundle->getBundle()->getName(),
                    'description' => $productBundle->getBundle()->getDescription(),
                ] : null,
            ];
        }

        return $result;
    }

    private function deleteExistingProductBundles(string $productId, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $productId));

        $existingBundles = $this->productBundleRepository->search($criteria, $context);

        if ($existingBundles->count() > 0) {
            $idsToDelete = [];
            foreach ($existingBundles->getEntities() as $entity) {
                $idsToDelete[] = ['id' => $entity->getId()];
            }

            $this->productBundleRepository->delete($idsToDelete, $context);
        }
    }

    private function createProductBundleEntries(string $productId, array $bundles, Context $context): void
    {
        $entitiesToCreate = [];
        $position = 0;

        foreach ($bundles as $bundleData) {
            if (!isset($bundleData['bundleId'])) {
                continue;
            }

            $entitiesToCreate[] = [
                'id' => Uuid::randomHex(),
                'productId' => $productId,
                'bundleId' => $bundleData['bundleId'],
                'position' => $position++,
                'bundleSlot' => $bundleData['bundleSlot'] ?? null,
                'priority' => $bundleData['priority'] ?? 0,
                'active' => $bundleData['active'] ?? true,
            ];
        }

        if (!empty($entitiesToCreate)) {
            $this->productBundleRepository->create($entitiesToCreate, $context);
        }
    }

    public function validateBundleData(array $bundles): array
    {
        $errors = [];

        foreach ($bundles as $index => $bundle) {
            if (empty($bundle['bundleId'])) {
                $errors[] = "Bundle at index {$index} is missing bundleId";
            }

            if (isset($bundle['priority']) && !is_numeric($bundle['priority'])) {
                $errors[] = "Bundle at index {$index} has invalid priority value";
            }

            if (isset($bundle['bundleSlot']) && !in_array($bundle['bundleSlot'], ['bundle_1', 'bundle_2', 'bundle_3', null])) {
                $errors[] = "Bundle at index {$index} has invalid bundle slot";
            }
        }

        return $errors;
    }
}
