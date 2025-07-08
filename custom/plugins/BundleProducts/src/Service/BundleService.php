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
    ) {
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
