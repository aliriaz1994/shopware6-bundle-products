<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Doctrine\DBAL\Connection;

class BundleProducts extends Plugin
{
    private const array CUSTOM_FIELDS = ['bundle_1', 'bundle_2', 'bundle_3'];

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Keep user data by default
        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Remove custom tables safely
        $this->removeTables();
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
    }

    /**
     * Safely removes all plugin tables and custom fields in the correct order
     */
    private function removeTables(): void
    {
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        // Disable foreign key checks temporarily to avoid constraint violations
        $connection->executeStatement('SET foreign_key_checks = 0');

        try {
            // Remove custom fields first
            $this->removeCustomFields($connection);

            // Drop tables in correct dependency order (child tables first, then parent)
            $tables = [
                'digipercep_product_bundle',  // Has FK to both bundle and product
                'digipercep_bundle_product',  // Has FK to both bundle and product
                'digipercep_bundle'           // Parent table
            ];

            foreach ($tables as $tableName) {
                $connection->executeStatement("DROP TABLE IF EXISTS `{$tableName}`");
            }
        } catch (\Exception $e) {
            // Log error but don't throw to prevent uninstall failure
            error_log("Error dropping table during plugin uninstall: " . $e->getMessage());
        } finally {
            // Always re-enable foreign key checks
            $connection->executeStatement('SET foreign_key_checks = 1');
        }
    }

    /**
     * Remove custom fields created by this plugin
     */
    private function removeCustomFields(Connection $connection): void
    {
        try {
            // Remove custom fields
            $placeholders = implode(', ', array_fill(0, count(self::CUSTOM_FIELDS), '?'));
            $connection->executeStatement(
                "DELETE FROM custom_field WHERE name IN ($placeholders)",
                self::CUSTOM_FIELDS
            );

            // Find and remove custom field set
            $customFieldSetId = $connection->fetchOne(
                'SELECT id FROM custom_field_set WHERE name = ?',
                ['bundles']
            );

            if ($customFieldSetId !== false) {
                // Remove relations
                $connection->executeStatement(
                    'DELETE FROM custom_field_set_relation WHERE set_id = ?',
                    [$customFieldSetId]
                );

                // Remove custom field set
                $connection->executeStatement(
                    'DELETE FROM custom_field_set WHERE id = ?',
                    [$customFieldSetId]
                );
            }
        } catch (\Exception $e) {
            error_log("Error removing custom fields during plugin uninstall: " . $e->getMessage());
        }
    }
}
