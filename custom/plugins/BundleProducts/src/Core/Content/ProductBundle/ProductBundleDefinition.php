<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\ProductBundle;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use DigiPercep\BundleProducts\Core\Content\Bundle\BundleDefinition;

class ProductBundleDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'digipercep_product_bundle';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ProductBundleCollection::class;
    }

    public function getEntityClass(): string
    {
        return ProductBundleEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new FkField('bundle_id', 'bundleId', BundleDefinition::class))->addFlags(new Required()),

            // Existing fields
            new IntField('position', 'position'),

            // New fields for bundle slots
            new StringField('bundle_slot', 'bundleSlot'),
            new IntField('priority', 'priority'),
            new BoolField('active', 'active'),

            // Associations
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
            new ManyToOneAssociationField('bundle', 'bundle_id', BundleDefinition::class, 'id', false),
        ]);
    }
}
