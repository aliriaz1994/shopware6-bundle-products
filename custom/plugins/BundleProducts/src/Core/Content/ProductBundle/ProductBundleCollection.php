<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\ProductBundle;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class ProductBundleCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'digipercep_product_bundle_collection';
    }

    protected function getExpectedClass(): string
    {
        return ProductBundleEntity::class;
    }

    public function getProductIds(): array
    {
        return $this->fmap(function (ProductBundleEntity $productBundle) {
            return $productBundle->getProductId();
        });
    }

    public function getBundleIds(): array
    {
        return $this->fmap(function (ProductBundleEntity $productBundle) {
            return $productBundle->getBundleId();
        });
    }

    public function filterByProduct(string $productId): ProductBundleCollection
    {
        return $this->filter(function (ProductBundleEntity $productBundle) use ($productId) {
            return $productBundle->getProductId() === $productId;
        });
    }

    public function filterByBundle(string $bundleId): ProductBundleCollection
    {
        return $this->filter(function (ProductBundleEntity $productBundle) use ($bundleId) {
            return $productBundle->getBundleId() === $bundleId;
        });
    }

    public function filterBySlot(string $bundleSlot): ProductBundleCollection
    {
        return $this->filter(function (ProductBundleEntity $productBundle) use ($bundleSlot) {
            return $productBundle->getBundleSlot() === $bundleSlot;
        });
    }

    public function filterByActive(bool $active = true): ProductBundleCollection
    {
        return $this->filter(function (ProductBundleEntity $productBundle) use ($active) {
            return $productBundle->getActive() === $active;
        });
    }

    public function sortByPriority(): ProductBundleCollection
    {
        $this->sort(function (ProductBundleEntity $a, ProductBundleEntity $b) {
            return ($a->getPriority() ?? 0) <=> ($b->getPriority() ?? 0);
        });

        return $this;
    }

    public function sortBySlot(): ProductBundleCollection
    {
        $this->sort(function (ProductBundleEntity $a, ProductBundleEntity $b) {
            // Sort order: bundle_1, bundle_2, bundle_3, then null values
            $slotOrder = ['bundle_1' => 1, 'bundle_2' => 2, 'bundle_3' => 3];

            $aOrder = $slotOrder[$a->getBundleSlot()] ?? 999;
            $bOrder = $slotOrder[$b->getBundleSlot()] ?? 999;

            return $aOrder <=> $bOrder;
        });

        return $this;
    }

    public function getBySlot(string $bundleSlot): ?ProductBundleEntity
    {
        return $this->filterBySlot($bundleSlot)->first();
    }

    public function getBundleSlots(): array
    {
        $slots = [];
        foreach ($this->getElements() as $productBundle) {
            if ($productBundle->getBundleSlot()) {
                $slots[] = $productBundle->getBundleSlot();
            }
        }
        return array_unique($slots);
    }
}