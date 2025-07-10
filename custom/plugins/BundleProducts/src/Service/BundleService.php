<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use DigiPercep\BundleProducts\Core\Content\Bundle\BundleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class BundleService
{
    public function __construct(
        private readonly EntityRepository $bundleRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    public function getBundleById(string $bundleId, Context $context): ?BundleEntity
    {
        $criteria = new Criteria([$bundleId]);
        $criteria->addAssociation('bundleProducts.product.cover');
        $criteria->addAssociation('bundleProducts.product.prices');
        $criteria->addAssociation('bundleProducts.product.tax');
        $criteria->addAssociation('bundleProducts.product.options.group');
        $criteria->addAssociation('translations');

        /** @var BundleEntity|null $bundle */
        $bundle = $this->bundleRepository->search($criteria, $context)->first();

        // Load parent products for variants separately
        if ($bundle && $bundle->getBundleProducts()) {
            $this->loadParentProductsForVariants($bundle, $context);
        }

        return $bundle;
    }

    /**
     * Load parent product data for variant products in the bundle
     */
    private function loadParentProductsForVariants(BundleEntity $bundle, Context $context): void
    {
        $parentIds = [];

        // Collect parent IDs from variant products
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();
            if ($product && $product->getParentId()) {
                $parentIds[] = $product->getParentId();
            }
        }

        if (empty($parentIds)) {
            return;
        }

        // Load parent products
        $parentCriteria = new Criteria(array_unique($parentIds));
        $parentCriteria->addAssociation('cover');
        $parentCriteria->addAssociation('media');
        $parentProducts = $this->productRepository->search($parentCriteria, $context);

        // Create map for quick lookup
        $parentMap = [];
        foreach ($parentProducts->getEntities() as $parent) {
            $parentMap[$parent->getId()] = $parent;
        }

        // Attach parent data to variant products
        foreach ($bundle->getBundleProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();
            if ($product && $product->getParentId() && isset($parentMap[$product->getParentId()])) {
                $product->addExtension('parentProduct', $parentMap[$product->getParentId()]);
            }
        }
    }
}