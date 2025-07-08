<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Service;

use DigiPercep\BundleProducts\Core\Content\Bundle\BundleCollection;
use DigiPercep\BundleProducts\Core\Content\Bundle\BundleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BundleService
{
    public function __construct(
        private readonly EntityRepository $bundleRepository,
    ) {
    }

    /**
     * Get bundles for a specific product
     * Accepts both Context and SalesChannelContext
     */
    public function getBundlesForProduct(string $productId, Context|SalesChannelContext $context): BundleCollection
    {
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
}
