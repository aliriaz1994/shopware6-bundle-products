<?php declare(strict_types=1);

namespace DigiPercep\BundleProducts\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1710000005CreateCustomFieldBundleProduct extends MigrationStep
{
    private const string CUSTOM_FIELD_SET_NAME = 'bundles';

    private const string ENTITY_NAME = 'product';

    private const array CUSTOM_FIELDS = [
        'bundle_1' => [
            'type' => 'select',
            'config' => [
                'label' => [
                    'en-GB' => 'Bundle 1',
                    'de-DE' => 'Bundle 1'
                ],
                'helpText' => [
                    'en-GB' => 'Select bundle for slot 1',
                    'de-DE' => 'Bundle für Slot 1 auswählen'
                ],
                'placeholder' => [
                    'en-GB' => 'Choose bundle...',
                    'de-DE' => 'Bundle auswählen...'
                ],
                'componentName' => 'sw-entity-single-select',
                'entity' => 'digipercep_bundle',
                'displayProperty' => 'name',
                'labelProperty' => 'name',
                'valueProperty' => 'id',
                'customFieldPosition' => 1
            ]
        ],
        'bundle_2' => [
            'type' => 'select',
            'config' => [
                'label' => [
                    'en-GB' => 'Bundle 2',
                    'de-DE' => 'Bundle 2'
                ],
                'helpText' => [
                    'en-GB' => 'Select bundle for slot 2',
                    'de-DE' => 'Bundle für Slot 2 auswählen'
                ],
                'placeholder' => [
                    'en-GB' => 'Choose bundle...',
                    'de-DE' => 'Bundle auswählen...'
                ],
                'componentName' => 'sw-entity-single-select',
                'entity' => 'digipercep_bundle',
                'displayProperty' => 'name',
                'labelProperty' => 'name',
                'valueProperty' => 'id',
                'customFieldPosition' => 2
            ]
        ],
        'bundle_3' => [
            'type' => 'select',
            'config' => [
                'label' => [
                    'en-GB' => 'Bundle 3',
                    'de-DE' => 'Bundle 3'
                ],
                'helpText' => [
                    'en-GB' => 'Select bundle for slot 3',
                    'de-DE' => 'Bundle für Slot 3 auswählen'
                ],
                'placeholder' => [
                    'en-GB' => 'Choose bundle...',
                    'de-DE' => 'Bundle auswählen...'
                ],
                'componentName' => 'sw-entity-single-select',
                'entity' => 'digipercep_bundle',
                'displayProperty' => 'name',
                'labelProperty' => 'name',
                'valueProperty' => 'id',
                'customFieldPosition' => 3
            ]
        ]
    ];

    private const array CUSTOM_FIELD_SET_CONFIG = [
        'label' => [
            'en-GB' => 'Bundles',
            'de-DE' => 'Bundles'
        ],
        'translated' => true,
        'displayType' => 'tabs',
        'position' => 1,
        'inline' => false
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1710000005;
    }

    /**
     * @throws Exception
     */
    #[\Override]
    public function update(Connection $connection): void
    {
        try {
            // Check if custom field set already exists
            $customFieldSetId = $this->findCustomFieldSetId($connection);

            if ($customFieldSetId === null) {
                $customFieldSetId = $this->createCustomFieldSet($connection);
                $this->createCustomFieldSetRelation($connection, $customFieldSetId);
            }

            $this->createCustomFields($connection, $customFieldSetId);
        } catch (Exception $e) {
            $this->logError('Error during migration update', $e);
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    #[\Override]
    public function updateDestructive(Connection $connection): void
    {
        try {
            $this->removeCustomFields($connection);
            $this->removeCustomFieldSetRelation($connection);
            $this->removeCustomFieldSet($connection);
        } catch (Exception $e) {
            $this->logError('Error during destructive migration', $e);
            throw $e;
        }
    }

    /**
     * Creates a custom field set if it doesn't exist
     * @throws Exception
     */
    private function createCustomFieldSet(Connection $connection): string
    {
        $customFieldSetId = Uuid::randomBytes();

        $connection->executeStatement(
            'INSERT INTO `custom_field_set` (`id`, `name`, `config`, `active`, `created_at`)
            VALUES (:id, :name, :config, 1, NOW())
            ON DUPLICATE KEY UPDATE `config` = VALUES(`config`), `updated_at` = NOW()',
            [
                'id' => $customFieldSetId,
                'name' => self::CUSTOM_FIELD_SET_NAME,
                'config' => json_encode(self::CUSTOM_FIELD_SET_CONFIG)
            ]
        );

        return $customFieldSetId;
    }

    /**
     * Creates relation between custom field set and entity if it doesn't exist
     * @throws Exception
     */
    private function createCustomFieldSetRelation(Connection $connection, string $customFieldSetId): void
    {
        // Check if relation already exists
        $existingRelation = $connection->fetchOne(
            'SELECT id FROM custom_field_set_relation WHERE set_id = ? AND entity_name = ?',
            [$customFieldSetId, self::ENTITY_NAME]
        );

        if ($existingRelation === false) {
            $connection->executeStatement(
                'INSERT INTO `custom_field_set_relation` (`id`, `set_id`, `entity_name`, `created_at`)
                VALUES (:id, :setId, :entityName, NOW())',
                [
                    'id' => Uuid::randomBytes(),
                    'setId' => $customFieldSetId,
                    'entityName' => self::ENTITY_NAME
                ]
            );
        }
    }

    /**
     * Creates all custom fields, skipping existing ones
     * @throws Exception
     */
    private function createCustomFields(Connection $connection, string $customFieldSetId): void
    {
        foreach (self::CUSTOM_FIELDS as $name => $field) {
            // Check if custom field already exists
            $existingField = $connection->fetchOne(
                'SELECT id FROM custom_field WHERE name = ?',
                [$name]
            );

            if ($existingField === false) {
                $connection->executeStatement(
                    'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
                    VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
                    [
                        'id' => Uuid::randomBytes(),
                        'name' => $name,
                        'type' => $field['type'],
                        'config' => json_encode($field['config']),
                        'setId' => $customFieldSetId
                    ]
                );
            } else {
                // Update existing field configuration
                $connection->executeStatement(
                    'UPDATE `custom_field` SET `config` = :config, `updated_at` = NOW() WHERE `name` = :name',
                    [
                        'config' => json_encode($field['config']),
                        'name' => $name
                    ]
                );
            }
        }
    }

    /**
     * Finds the custom field set ID
     * @param Connection $connection
     * @return string|null
     * @throws Exception
     */
    private function findCustomFieldSetId(Connection $connection): ?string
    {
        /** @var string|false $result */
        $result = $connection->fetchOne(
            'SELECT id FROM custom_field_set WHERE name = ?',
            [self::CUSTOM_FIELD_SET_NAME]
        );

        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * Removes custom fields
     * @throws Exception
     */
    private function removeCustomFields(Connection $connection): void
    {
        $fieldNames = array_keys(self::CUSTOM_FIELDS);
        $placeholders = implode(', ', array_fill(0, count($fieldNames), '?'));

        $connection->executeStatement(
            "DELETE FROM custom_field WHERE name IN ($placeholders)",
            $fieldNames
        );
    }

    /**
     * Removes custom field set relation
     * @throws Exception
     */
    private function removeCustomFieldSetRelation(Connection $connection): void
    {
        $customFieldSetId = $this->findCustomFieldSetId($connection);
        if ($customFieldSetId !== null) {
            $connection->executeStatement(
                'DELETE FROM custom_field_set_relation WHERE entity_name = ? AND set_id = ?',
                [self::ENTITY_NAME, $customFieldSetId]
            );
        }
    }

    /**
     * Removes custom field set
     * @throws Exception
     */
    private function removeCustomFieldSet(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM custom_field_set WHERE name = ?',
            [self::CUSTOM_FIELD_SET_NAME]
        );
    }

    /**
     * Logs error message
     */
    private function logError(string $message, \Exception $exception): void
    {
        $this->logger?->error(
            $message,
            [
                'exception' => $exception,
                'migration' => self::class
            ]
        );
    }
}
