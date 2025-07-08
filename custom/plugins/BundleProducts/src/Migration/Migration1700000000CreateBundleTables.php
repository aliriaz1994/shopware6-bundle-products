<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1700000000CreateBundleTables extends MigrationStep
{
    private const string TABLE_BUNDLE = 'digipercep_bundle';
    private const string TABLE_BUNDLE_PRODUCT = 'digipercep_bundle_product';
    private const string TABLE_PRODUCT_BUNDLE = 'digipercep_product_bundle';

    public function getCreationTimestamp(): int
    {
        return 1700000000;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $this->createBundleTable($connection);
        $this->createBundleProductTable($connection);
        $this->createProductBundleTable($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Drop tables in reverse order (child tables first)
        $this->dropTableIfExists($connection, self::TABLE_PRODUCT_BUNDLE);
        $this->dropTableIfExists($connection, self::TABLE_BUNDLE_PRODUCT);
        $this->dropTableIfExists($connection, self::TABLE_BUNDLE);
    }

    /**
     * @throws Exception
     */
    private function createBundleTable(Connection $connection): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS `digipercep_bundle` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `discount` DOUBLE NOT NULL,
                `discount_type` VARCHAR(20) NOT NULL DEFAULT 'percentage',
                `is_selectable` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `display_mode` VARCHAR(50) NULL,
                `priority` INT(11) NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx_active` (`active`),
                KEY `idx_priority` (`priority`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }

    /**
     * @throws Exception
     */
    private function createBundleProductTable(Connection $connection): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS `digipercep_bundle_product` (
                `id` BINARY(16) NOT NULL,
                `bundle_id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `quantity` INT(11) NOT NULL DEFAULT 1,
                `position` INT(11) NOT NULL DEFAULT 0,
                `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_bundle_product` (`bundle_id`, `product_id`),
                KEY `fk.digipercep_bundle_product.bundle_id` (`bundle_id`),
                KEY `fk.digipercep_bundle_product.product_id` (`product_id`),
                KEY `idx_position` (`position`),
                KEY `idx_optional` (`is_optional`),
                CONSTRAINT `fk.digipercep_bundle_product.bundle_id` FOREIGN KEY (`bundle_id`)
                    REFERENCES `digipercep_bundle` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.digipercep_bundle_product.product_id` FOREIGN KEY (`product_id`)
                    REFERENCES `product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }

    /**
     * @throws Exception
     */
    private function createProductBundleTable(Connection $connection): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS `digipercep_product_bundle` (
                `id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `bundle_id` BINARY(16) NOT NULL,
                `position` INT(11) NOT NULL DEFAULT 0,
                `bundle_slot` VARCHAR(50) NULL COMMENT 'bundle_1, bundle_2, or bundle_3',
                `priority` INT(11) NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_product_bundle_slot` (`product_id`, `bundle_slot`),
                KEY `fk.digipercep_product_bundle.product_id` (`product_id`),
                KEY `fk.digipercep_product_bundle.bundle_id` (`bundle_id`),
                KEY `idx_bundle_slot` (`bundle_slot`),
                KEY `idx_active` (`active`),
                KEY `idx_position` (`position`),
                KEY `idx_priority` (`priority`),
                CONSTRAINT `fk.digipercep_product_bundle.product_id` FOREIGN KEY (`product_id`)
                    REFERENCES `product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.digipercep_product_bundle.bundle_id` FOREIGN KEY (`bundle_id`)
                    REFERENCES `digipercep_bundle` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }
}
