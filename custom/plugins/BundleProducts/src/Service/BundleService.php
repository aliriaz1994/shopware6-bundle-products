<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use DigiPercep\BundleProducts\Core\Content\Bundle\BundleCollection;
use DigiPercep\BundleProducts\Core\Content\Bundle\BundleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;

class BundleService
{
    public function __construct(
        private EntityRepository $bundleRepository,
        private EntityRepository $bundleProductRepository,
        private EntityRepository $productBundleRepository,
        private EntityRepository $productRepository,
        private QuantityPriceCalculator $quantityPriceCalculator,
        private CurrencyFormatter $currencyFormatter
    ) {
    }

    /**
     * Get bundles for a specific product
     * Accepts both Context and SalesChannelContext
     */
    public function getBundlesForProduct(string $productId, Context|SalesChannelContext $context): BundleCollection
    {
        // Convert SalesChannelContext to Context if needed
        if ($context instanceof SalesChannelContext) {
            $context = $context->getContext();
        }

        $criteria = new Criteria();
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('productBundles.productId', $productId),
                new EqualsFilter('active', true)
            ])
        );

        // Add associations
        $criteria->addAssociation('bundleProducts.product');
        $criteria->addAssociation('productBundles');

        /** @var BundleCollection $bundles */
        $bundles = $this->bundleRepository->search($criteria, $context)->getEntities();

        return $bundles;
    }

    public function getProductsForBundle(string $bundleId, Context $context): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('bundleProducts.bundleId', $bundleId));
        $criteria->addAssociation('cover');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('categories');

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        return $products;
    }

    public function getBundleById(string $bundleId, Context $context): ?BundleEntity
    {
        $criteria = new Criteria([$bundleId]);
        $criteria->addAssociation('bundleProducts.product.cover');
        $criteria->addAssociation('bundleProducts.product.prices');
        $criteria->addAssociation('bundleProducts.product.tax');
        $criteria->addAssociation('translations');

        /** @var BundleEntity|null $bundle */
        $bundle = $this->bundleRepository->search($criteria, $context)->first();

        return $bundle;
    }

    public function createBundle(array $data, Context $context): void
    {
        $this->bundleRepository->create([$data], $context);
    }

    public function updateBundle(string $bundleId, array $data, Context $context): void
    {
        $data['id'] = $bundleId;
        $this->bundleRepository->update([$data], $context);
    }

    public function deleteBundle(string $bundleId, Context $context): void
    {
        $this->bundleRepository->delete([['id' => $bundleId]], $context);
    }

    public function assignBundleToProduct(string $productId, string $bundleId, Context $context, int $position = 0): void
    {
        $this->productBundleRepository->create([
            [
                'productId' => $productId,
                'bundleId' => $bundleId,
                'position' => $position,
            ]
        ], $context);
    }

    public function removeBundleFromProduct(string $productId, string $bundleId, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $productId));
        $criteria->addFilter(new EqualsFilter('bundleId', $bundleId));

        $result = $this->productBundleRepository->search($criteria, $context);
        if ($result->getTotal() > 0) {
            $ids = array_map(fn($entity) => ['id' => $entity->getId()], $result->getElements());
            $this->productBundleRepository->delete($ids, $context);
        }
    }

    public function getActiveBundles(Context $context, ?string $salesChannelId = null): BundleCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('priority', FieldSorting::DESCENDING));

        if ($salesChannelId) {
            $criteria->addFilter(
                new EqualsAnyFilter('salesChannels.id', [$salesChannelId, null])
            );
        }

        /** @var BundleCollection $bundles */
        $bundles = $this->bundleRepository->search($criteria, $context)->getEntities();

        return $bundles;
    }

    /**
     * Get bundles with their products for frontend display
     */
    public function getBundlesWithProductsForDisplay(string $productId, Context|SalesChannelContext $context): BundleCollection
    {
        // Convert SalesChannelContext to Context if needed
        if ($context instanceof SalesChannelContext) {
            $context = $context->getContext();
        }

        $criteria = new Criteria();
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('productBundles.productId', $productId),
                new EqualsFilter('active', true)
            ])
        );

        // Add comprehensive associations for frontend display
        $criteria->addAssociation('bundleProducts.product.cover.media');
        $criteria->addAssociation('bundleProducts.product.prices');
        $criteria->addAssociation('bundleProducts.product.tax');
        $criteria->addAssociation('productBundles');

        /** @var BundleCollection $bundles */
        $bundles = $this->bundleRepository->search($criteria, $context)->getEntities();

        return $bundles;
    }
}
