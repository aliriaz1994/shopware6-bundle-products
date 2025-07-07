<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\ProductBundle;

use DigiPercep\BundleProducts\Core\Content\Bundle\BundleEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ProductBundleEntity extends Entity
{
    use EntityIdTrait;

    protected string $productId;
    protected string $bundleId;
    protected int $position = 0;
    protected ?string $bundleSlot = null;
    protected ?int $priority = null;
    protected bool $active = true;

    // Associations
    protected ?ProductEntity $product = null;
    protected ?BundleEntity $bundle = null;

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function getBundleId(): string
    {
        return $this->bundleId;
    }

    public function setBundleId(string $bundleId): void
    {
        $this->bundleId = $bundleId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getBundleSlot(): ?string
    {
        return $this->bundleSlot;
    }

    public function setBundleSlot(?string $bundleSlot): void
    {
        $this->bundleSlot = $bundleSlot;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(?int $priority): void
    {
        $this->priority = $priority;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }

    public function getBundle(): ?BundleEntity
    {
        return $this->bundle;
    }

    public function setBundle(?BundleEntity $bundle): void
    {
        $this->bundle = $bundle;
    }
}