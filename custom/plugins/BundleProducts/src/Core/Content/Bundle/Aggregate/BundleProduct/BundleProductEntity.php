<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\Bundle\Aggregate\BundleProduct;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Content\Product\ProductEntity;
use DigiPercep\BundleProducts\Core\Content\Bundle\BundleEntity;

class BundleProductEntity extends Entity
{
    use EntityIdTrait;

    protected string $bundleId;
    protected string $productId;
    protected int $quantity = 1;
    protected int $position = 0;
    protected bool $isOptional = false;
    protected ?BundleEntity $bundle = null;
    protected ?ProductEntity $product = null;

    public function getBundleId(): string
    {
        return $this->bundleId;
    }

    public function setBundleId(string $bundleId): void
    {
        $this->bundleId = $bundleId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isOptional(): bool
    {
        return $this->isOptional;
    }

    public function setIsOptional(bool $isOptional): void
    {
        $this->isOptional = $isOptional;
    }

    public function getBundle(): ?BundleEntity
    {
        return $this->bundle;
    }

    public function setBundle(?BundleEntity $bundle): void
    {
        $this->bundle = $bundle;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }
}
