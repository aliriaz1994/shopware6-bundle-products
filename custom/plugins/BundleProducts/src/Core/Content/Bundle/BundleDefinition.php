<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Core\Content\Bundle;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use DigiPercep\BundleProducts\Core\Content\Bundle\Aggregate\BundleProduct\BundleProductDefinition;

class BundleDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'digipercep_bundle';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BundleCollection::class;
    }

    public function getEntityClass(): string
    {
        return BundleEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),

            // Basic fields - all marked as ApiAware for API access
            (new StringField('name', 'name'))
                ->addFlags(new ApiAware(), new Required(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),

            (new FloatField('discount', 'discount'))
                ->addFlags(new ApiAware(), new Required()),

            (new StringField('discount_type', 'discountType', 20))
                ->addFlags(new ApiAware(), new Required()),

            (new BoolField('active', 'active'))
                ->addFlags(new ApiAware()),

            (new IntField('priority', 'priority'))
                ->addFlags(new ApiAware()),

            // Timestamps
            (new DateTimeField('created_at', 'createdAt'))
                ->addFlags(new ApiAware(), new Required()),

            (new DateTimeField('updated_at', 'updatedAt'))
                ->addFlags(new ApiAware()),

            // Associations - mark as ApiAware for API access
            (new OneToManyAssociationField('bundleProducts', BundleProductDefinition::class, 'bundle_id'))
                ->addFlags(new ApiAware(), new CascadeDelete()),
        ]);
    }
}