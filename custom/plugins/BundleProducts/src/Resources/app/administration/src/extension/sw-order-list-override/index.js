// src/Resources/app/administration/src/extension/sw-order-list-override/index.js

import template from './sw-order-list-override.html.twig';
import './sw-order-list-override.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-order-list', {
    template,

    data() {
        return {
            // Store bundle information for each order
            orderBundleData: new Map(),
            loadingBundleData: false
        };
    },

    computed: {
        orderColumns() {
            // Get original columns first
            const originalColumns = this.$super('orderColumns');

            // Clone the array to avoid modifying the original
            const columns = [...originalColumns];

            // Find the position after order number to insert bundle columns
            const orderNumberIndex = columns.findIndex(col => col.property === 'orderNumber');
            const insertIndex = orderNumberIndex !== -1 ? orderNumberIndex + 1 : 1;

            const bundleColumns = [
                {
                    property: 'bundleInfo',
                    label: 'Bundle Info',
                    routerLink: 'sw.order.detail',
                    allowResize: true,
                    sortable: false,
                    width: '200px'
                },
                {
                    property: 'bundleSavings',
                    label: 'Bundle Savings',
                    allowResize: true,
                    sortable: false,
                    width: '120px'
                }
            ];

            // Insert bundle columns safely
            columns.splice(insertIndex, 0, ...bundleColumns);
            return columns;
        },

        // Override criteria safely without breaking parent functionality
        orderCriteria() {
            // Get the parent criteria first
            let criteria;
            try {
                criteria = this.$super('orderCriteria');
            } catch (e) {
                // If parent doesn't exist, create new criteria
                criteria = new Criteria(this.page, this.limit);
            }

            // Safely add line items association
            if (criteria && typeof criteria.addAssociation === 'function') {
                criteria.addAssociation('lineItems');
            }

            return criteria;
        }
    },

    watch: {
        orders: {
            handler(newOrders) {
                if (newOrders && Array.isArray(newOrders) && newOrders.length > 0) {
                    this.processOrderBundleData(newOrders);
                }
            },
            immediate: true
        }
    },

    methods: {
        // Safely process bundle data for all orders
        processOrderBundleData(orders) {
            if (!Array.isArray(orders)) {
                return;
            }

            this.loadingBundleData = true;

            try {
                orders.forEach(order => {
                    if (order && order.id) {
                        this.extractBundleDataForOrder(order);
                    }
                });
            } catch (error) {
                console.error('Error processing bundle data:', error);
            } finally {
                this.loadingBundleData = false;
            }
        },

        // Extract bundle data for a specific order
        extractBundleDataForOrder(order) {
            if (!order || !order.id) {
                return;
            }

            try {
                // Check if lineItems exists and is an array
                let lineItems = [];

                if (order.lineItems && Array.isArray(order.lineItems)) {
                    lineItems = order.lineItems;
                } else if (order.lineItems && order.lineItems.elements) {
                    // Handle EntityCollection format
                    lineItems = Object.values(order.lineItems.elements);
                }

                const bundleItems = lineItems.filter(item => {
                    if (!item || item.type !== 'custom') return false;

                    let payload;
                    try {
                        payload = typeof item.payload === 'string'
                            ? JSON.parse(item.payload)
                            : item.payload;
                    } catch (e) {
                        return false;
                    }

                    return payload && payload.isBundle === true;
                });

                if (bundleItems.length > 0) {
                    const bundleData = bundleItems.map(item => {
                        let payload;
                        try {
                            payload = typeof item.payload === 'string'
                                ? JSON.parse(item.payload)
                                : item.payload;
                        } catch (e) {
                            return null;
                        }

                        if (!payload) return null;

                        return {
                            bundleId: payload.bundleId || null,
                            bundleDescription: payload.bundleDescription || 'Bundle',
                            mainProductName: payload.mainProductName || '',
                            totalProductCount: parseInt(payload.totalProductCount) || 0,
                            originalPrice: parseFloat(payload.originalPrice) || 0,
                            finalPrice: parseFloat(payload.finalPrice) || 0,
                            savings: parseFloat(payload.savings) || 0,
                            discountValue: parseFloat(payload.discountValue) || 0,
                            discountType: payload.discountType || 'percentage',
                            bundleProducts: Array.isArray(payload.bundleProducts) ? payload.bundleProducts : [],
                            allBundleProducts: Array.isArray(payload.allBundleProducts) ? payload.allBundleProducts : [],
                            lineItemId: item.id || null,
                            lineItemPrice: parseFloat(item.price) || 0,
                            lineItemTotalPrice: parseFloat(item.totalPrice) || 0,
                            lineItemQuantity: parseInt(item.quantity) || 0
                        };
                    }).filter(Boolean);

                    this.orderBundleData.set(order.id, bundleData);
                }
            } catch (error) {
                console.error('Error extracting bundle data for order:', order.id, error);
            }
        },

        // Get bundle information for display
        getBundleInfo(order) {
            if (!order || !order.id) {
                return null;
            }
            return this.orderBundleData.get(order.id) || null;
        },

        // Format bundle info for display
        formatBundleInfo(bundleInfo) {
            if (!bundleInfo || !Array.isArray(bundleInfo) || bundleInfo.length === 0) {
                return '-';
            }

            if (bundleInfo.length === 1) {
                const bundle = bundleInfo[0];
                return `${bundle.bundleDescription} (${bundle.totalProductCount} items)`;
            }

            const totalProducts = bundleInfo.reduce((sum, bundle) => sum + (bundle.totalProductCount || 0), 0);
            return `${bundleInfo.length} bundles (${totalProducts} items total)`;
        },

        // Format bundle savings
        formatBundleSavings(bundleInfo) {
            if (!bundleInfo || !Array.isArray(bundleInfo) || bundleInfo.length === 0) {
                return '-';
            }

            const totalSavings = bundleInfo.reduce((sum, bundle) => sum + (bundle.savings || 0), 0);

            if (totalSavings > 0) {
                return `€${totalSavings.toFixed(2)}`;
            }

            return '-';
        },

        // Check if order has bundles
        hasBundle(order) {
            const bundleInfo = this.getBundleInfo(order);
            return bundleInfo && Array.isArray(bundleInfo) && bundleInfo.length > 0;
        },

        // Get bundle count for order
        getBundleCount(order) {
            const bundleInfo = this.getBundleInfo(order);
            return bundleInfo && Array.isArray(bundleInfo) ? bundleInfo.length : 0;
        },

        // Get total savings for order
        getTotalSavings(order) {
            const bundleInfo = this.getBundleInfo(order);
            if (!bundleInfo || !Array.isArray(bundleInfo)) return 0;
            return bundleInfo.reduce((sum, bundle) => sum + (bundle.savings || 0), 0);
        },

        // Override getList method safely
        getList() {
            // Call parent getList first
            const parentResult = this.$super('getList');

            // Return the result (could be a Promise)
            return parentResult;
        }
    }
});