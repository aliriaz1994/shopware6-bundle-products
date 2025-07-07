<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\Bundle\Aggregate\BundleProduct;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(BundleProductEntity $entity)
 * @method void set(string $key, BundleProductEntity $entity)
 * @method BundleProductEntity[] getIterator()
 * @method BundleProductEntity[] getElements()
 * @method BundleProductEntity|null get(string $key)
 * @method BundleProductEntity|null first()
 * @method BundleProductEntity|null last()
 */
class BundleProductCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BundleProductEntity::class;
    }
}
