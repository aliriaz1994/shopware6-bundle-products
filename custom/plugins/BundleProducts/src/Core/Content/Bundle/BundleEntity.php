<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\Bundle;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use DigiPercep\BundleProducts\Core\Content\Bundle\Aggregate\BundleProduct\BundleProductCollection;
use DigiPercep\BundleProducts\Core\Content\ProductBundle\ProductBundleCollection;

class BundleEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $name = null;
    protected ?string $description = null;
    protected float $discount = 0.0;
    protected string $discountType = 'percentage';
    protected bool $isSelectable = false;
    protected bool $active = true;
    protected int $priority = 0;
    protected ?BundleProductCollection $bundleProducts = null;
    protected ?ProductBundleCollection $productBundles = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getDiscount(): float
    {
        return $this->discount;
    }

    public function setDiscount(float $discount): void
    {
        $this->discount = $discount;
    }

    public function getDiscountType(): string
    {
        return $this->discountType;
    }

    public function setDiscountType(string $discountType): void
    {
        $this->discountType = $discountType;
    }

    public function isSelectable(): bool
    {
        return $this->isSelectable;
    }

    public function setIsSelectable(bool $isSelectable): void
    {
        $this->isSelectable = $isSelectable;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getBundleProducts(): ?BundleProductCollection
    {
        return $this->bundleProducts;
    }

    public function setBundleProducts(?BundleProductCollection $bundleProducts): void
    {
        $this->bundleProducts = $bundleProducts;
    }

    public function getProductBundles(): ?ProductBundleCollection
    {
        return $this->productBundles;
    }

    public function setProductBundles(?ProductBundleCollection $productBundles): void
    {
        $this->productBundles = $productBundles;
    }
}
