// Enhanced component with custom field support
// File: src/Resources/app/administration/src/module/digipercep-bundle/component/digipercep-product-bundle-assignment/index.js

import template from './digipercep-product-bundle-assignment.html.twig';
import './digipercep-product-bundle-assignment.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('digipercep-product-bundle-assignment', {
    template,

    inject: ['repositoryFactory', 'loginService'],

    mixins: [Mixin.getByName('notification')],

    props: {
        product: {
            type: Object,
            required: true
        }
    },

    data() {
        return {
            bundleSlots: {
                bundle_1: {
                    bundleId: null,
                    priority: 0,
                    bundle: null
                },
                bundle_2: {
                    bundleId: null,
                    priority: 0,
                    bundle: null
                },
                bundle_3: {
                    bundleId: null,
                    priority: 0,
                    bundle: null
                }
            },
            isLoading: false,
            originalData: null,
            useCustomFields: false, // Toggle between database and custom fields
            showMigrationOptions: false
        };
    },

    computed: {
        productId() {
            return this.product?.id || null;
        },

        bundleRepository() {
            return this.repositoryFactory.create('digipercep_bundle');
        },

        productRepository() {
            return this.repositoryFactory.create('product');
        },

        bundleCriteria() {
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.setLimit(25);
            return criteria;
        },

        hasAssignedBundles() {
            return Object.values(this.bundleSlots).some(slot => slot.bundleId);
        },

        // Check if product has custom field data
        hasCustomFieldData() {
            return this.product?.customFields?.digipercep_bundle_product !== null &&
                this.product?.customFields?.digipercep_bundle_product !== undefined;
        }
    },

    watch: {
        product: {
            handler() {
                this.loadProductBundles();
            },
            immediate: true
        }
    },

    methods: {
        async loadProductBundles() {
            if (!this.productId) {
                this.resetBundleSlots();
                return;
            }

            this.isLoading = true;

            try {
                let endpoint = `/api/digipercep-product/${this.productId}/bundles`;

                // If using custom fields mode, load from custom field endpoint
                if (this.useCustomFields) {
                    endpoint = `/api/digipercep-product/${this.productId}/bundles/custom-field`;
                }

                const response = await fetch(endpoint, {
                    method: 'GET',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    }
                });

                if (response.ok) {
                    const result = await response.json();

                    if (this.useCustomFields) {
                        this.setupCustomFieldBundleSlots(result.data || {});
                    } else {
                        this.setupBundleSlots(result.bundleSlots || {});
                    }

                    this.originalData = JSON.parse(JSON.stringify(this.bundleSlots));
                } else {
                    throw new Error('Failed to load product bundles');
                }
            } catch (error) {
                console.error('Error loading product bundles:', error);
                this.createNotificationError({
                    message: `Error loading bundle assignments: ${error.message}`
                });
                this.resetBundleSlots();
            } finally {
                this.isLoading = false;
            }
        },

        setupBundleSlots(slotsData) {
            this.resetBundleSlots();

            Object.keys(this.bundleSlots).forEach(slotName => {
                const slotData = slotsData[slotName];
                if (slotData && slotData.bundleId) {
                    this.bundleSlots[slotName] = {
                        bundleId: slotData.bundleId,
                        priority: slotData.priority || 0,
                        bundle: slotData.bundle || null
                    };
                }
            });
        },

        setupCustomFieldBundleSlots(customFieldData) {
            this.resetBundleSlots();

            Object.keys(this.bundleSlots).forEach(async slotName => {
                const slotData = customFieldData[slotName];
                if (slotData && slotData.bundleId) {
                    // Load bundle details for custom field data
                    try {
                        const bundle = await this.bundleRepository.get(slotData.bundleId, Shopware.Context.api);
                        this.bundleSlots[slotName] = {
                            bundleId: slotData.bundleId,
                            priority: slotData.priority || 0,
                            bundle: {
                                id: bundle.id,
                                name: bundle.name,
                                discount: bundle.discount,
                                discountType: bundle.discountType,
                                active: bundle.active
                            }
                        };
                    } catch (error) {
                        console.error('Error loading bundle for custom field:', error);
                    }
                }
            });
        },

        resetBundleSlots() {
            this.bundleSlots = {
                bundle_1: { bundleId: null, priority: 0, bundle: null },
                bundle_2: { bundleId: null, priority: 0, bundle: null },
                bundle_3: { bundleId: null, priority: 0, bundle: null }
            };
        },

        async onBundleChanged(slotName, bundleId) {
            if (!bundleId) {
                this.clearBundleSlot(slotName);
                return;
            }

            try {
                const bundle = await this.bundleRepository.get(bundleId, Shopware.Context.api);

                this.bundleSlots[slotName].bundleId = bundleId;
                this.bundleSlots[slotName].bundle = {
                    id: bundle.id,
                    name: bundle.name,
                    discount: bundle.discount,
                    discountType: bundle.discountType,
                    active: bundle.active
                };

                this.createNotificationInfo({
                    message: `${bundle.name} selected for ${this.formatSlotLabel(slotName)}`
                });
            } catch (error) {
                console.error('Error loading bundle details:', error);
                this.createNotificationError({
                    message: `Error loading bundle details: ${error.message}`
                });
                this.clearBundleSlot(slotName);
            }
        },

        clearBundleSlot(slotName) {
            this.bundleSlots[slotName] = {
                bundleId: null,
                priority: 0,
                bundle: null
            };

            this.createNotificationInfo({
                message: `${this.formatSlotLabel(slotName)} cleared`
            });
        },

        async onSaveAssignments() {
            if (!this.productId) {
                this.createNotificationError({
                    message: 'Product ID is required to save assignments'
                });
                return;
            }

            this.isLoading = true;

            try {
                let endpoint = `/api/digipercep-product/${this.productId}/bundles/bulk`;

                // If using custom fields mode, save to custom field endpoint
                if (this.useCustomFields) {
                    endpoint = `/api/digipercep-product/${this.productId}/bundles/custom-field`;
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        bundleSlots: this.prepareBundleSlotsForSave()
                    })
                });

                if (response.ok) {
                    const result = await response.json();

                    this.createNotificationSuccess({
                        message: result.message || 'Bundle assignments saved successfully'
                    });

                    this.originalData = JSON.parse(JSON.stringify(this.bundleSlots));
                    await this.loadProductBundles();
                } else {
                    const error = await response.json();
                    throw new Error(error.errors?.[0]?.detail || 'Failed to save assignments');
                }
            } catch (error) {
                console.error('Error saving bundle assignments:', error);
                this.createNotificationError({
                    message: `Error saving assignments: ${error.message}`
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onMigrateBundles(direction) {
            if (!this.productId) {
                this.createNotificationError({
                    message: 'Product ID is required for migration'
                });
                return;
            }

            this.isLoading = true;

            try {
                const response = await fetch(`/api/digipercep-product/${this.productId}/bundles/migrate`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        direction: direction
                    })
                });

                if (response.ok) {
                    const result = await response.json();

                    this.createNotificationSuccess({
                        message: result.message || 'Bundle assignments migrated successfully'
                    });

                    await this.loadProductBundles();
                } else {
                    const error = await response.json();
                    throw new Error(error.errors?.[0]?.detail || 'Failed to migrate assignments');
                }
            } catch (error) {
                console.error('Error migrating bundle assignments:', error);
                this.createNotificationError({
                    message: `Error migrating assignments: ${error.message}`
                });
            } finally {
                this.isLoading = false;
            }
        },

        prepareBundleSlotsForSave() {
            const bundleSlotsToSave = {};

            Object.keys(this.bundleSlots).forEach(slotName => {
                const slot = this.bundleSlots[slotName];
                if (slot.bundleId) {
                    bundleSlotsToSave[slotName] = {
                        bundleId: slot.bundleId,
                        priority: slot.priority || 0
                    };
                } else {
                    bundleSlotsToSave[slotName] = null;
                }
            });

            return bundleSlotsToSave;
        },

        onRefresh() {
            this.loadProductBundles();
        },

        onToggleStorageMode() {
            this.useCustomFields = !this.useCustomFields;
            this.loadProductBundles();
        },

        formatSlotLabel(slotName) {
            const slotLabels = {
                bundle_1: 'Bundle 1',
                bundle_2: 'Bundle 2',
                bundle_3: 'Bundle 3'
            };
            return slotLabels[slotName] || slotName;
        },

        hasUnsavedChanges() {
            if (!this.originalData) return false;
            return JSON.stringify(this.bundleSlots) !== JSON.stringify(this.originalData);
        }
    }
});