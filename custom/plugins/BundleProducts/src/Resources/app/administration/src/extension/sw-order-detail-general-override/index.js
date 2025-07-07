// Optimized Vue.js component for Shopware 6.6 Bundle Details
import template from './sw-order-detail-general-override.html.twig';
import './sw-order-detail-general-override.scss';

const { Component } = Shopware;

Component.override('sw-order-detail-general', {
    template,

    data() {
        return {
            lineItemBundleData: new Map(),
            showBundleDetailsModal: false,
            selectedBundleDetails: null,
            loadingBundleData: false,
            bundleButtonsAdded: false
        };
    },

    computed: {
        enhancedLineItems() {
            const lineItems = this.getLineItemsArray();
            return lineItems.map(lineItem => ({
                ...lineItem,
                isBundle: this.isBundleLineItem(lineItem),
                bundleData: this.getBundleDataForLineItem(lineItem)
            }));
        }
    },

    mounted() {
        this.processBundleData();
    },

    watch: {
        'order.lineItems': {
            handler(newLineItems) {
                if (newLineItems && !this.loadingBundleData) {
                    this.bundleButtonsAdded = false;
                    this.processBundleData();
                }
            },
            immediate: true,
            deep: false
        },

        selectedBundleDetails: {
            handler() {
                this.$forceUpdate();
            },
            deep: true
        }
    },

    methods: {
        processBundleData() {
            if (!this.order?.lineItems) return;
            this.loadingBundleData = true;

            try {
                const lineItems = this.getLineItemsArray();
                this.lineItemBundleData.clear();
                lineItems.forEach(lineItem => {
                    if (lineItem?.id) {
                        this.extractBundleDataForLineItem(lineItem);
                    }
                });

                this.scheduleButtonAddition();
            } catch (error) {
                this.handleError('Error processing bundle data', error);
            } finally {
                this.loadingBundleData = false;
            }
        },

        scheduleButtonAddition() {
            this.$nextTick(() => {
                setTimeout(() => {
                    if (!this.bundleButtonsAdded) {
                        this.addBundleButtons();
                    }
                }, 300);
            });
        },

        addBundleButtons() {
            try {
                if (this.bundleButtonsAdded) return;

                this.removePreviousBundleButtons();
                const lineItemRows = this.getNonEditableGridRows();

                if (lineItemRows.length === 0) return;

                const lineItems = this.getLineItemsArray();
                this.processRowsWithLineItems(lineItemRows, lineItems);

                this.bundleButtonsAdded = true;
            } catch (error) {
                this.handleError('Error adding bundle buttons', error);
            }
        },

        removePreviousBundleButtons() {
            document.querySelectorAll('.bundle-details-button').forEach(el => el.remove());
        },

        getNonEditableGridRows() {
            const allRows = document.querySelectorAll('.sw-data-grid__row');
            return Array.from(allRows).filter(row => {
                const isHeader = row.classList.contains('sw-data-grid__row--header') ||
                    row.querySelector('th') ||
                    row.querySelector('.sw-data-grid__cell--header');
                const isEditable = row.classList.contains('is--inline-edit');
                return !isHeader && !isEditable;
            });
        },

        processRowsWithLineItems(rows, lineItems) {
            rows.forEach((row, index) => {
                try {
                    if (index < lineItems.length) {
                        const lineItem = lineItems[index];
                        if (this.lineItemBundleData.has(lineItem.id)) {
                            this.addBundleButtonToRow(row, lineItem);
                        }
                    }
                } catch (error) {
                    this.handleError(`Error processing row ${index + 1}`, error);
                }
            });
        },

        addBundleButtonToRow(row, lineItem) {
            try {
                if (row.querySelector('.bundle-details-button')) return;

                const targetCell = this.findTargetCell(row, lineItem);
                if (!targetCell) return;

                const bundleButton = this.createBundleButton(lineItem);
                const cellContent = this.getCellContent(targetCell);

                this.setupCellLayout(cellContent);
                cellContent.appendChild(bundleButton);
            } catch (error) {
                this.handleError('Error adding bundle button to row', error);
            }
        },

        findTargetCell(row, lineItem) {
            // Strategy 1: Label column by class
            let targetCell = row.querySelector('.sw-data-grid__cell--label');
            if (targetCell) return targetCell;

            // Strategy 2: Cell containing product name
            const allCells = row.querySelectorAll('.sw-data-grid__cell:not(.sw-data-grid__cell--selection)');
            for (const cell of allCells) {
                if (cell.textContent.trim().includes(lineItem.label)) {
                    return cell;
                }
            }

            // Strategy 3: Third column (usually label)
            return allCells.length >= 3 ? allCells[2] : null;
        },

        createBundleButton(lineItem) {
            const button = document.createElement('button');
            button.className = 'bundle-details-button';
            button.innerHTML = `
                <span class="bundle-icon">📦</span>
                <span class="bundle-text">Bundle Details</span>
            `;
            button.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.showBundleDetails(lineItem);
            };
            return button;
        },

        getCellContent(targetCell) {
            return targetCell.querySelector('.sw-data-grid__cell-content') ||
                targetCell.querySelector('.sw-data-grid__cell-value') ||
                targetCell;
        },

        setupCellLayout(cellContent) {
            const currentDisplay = window.getComputedStyle(cellContent).display;
            if (currentDisplay !== 'flex') {
                Object.assign(cellContent.style, {
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between'
                });
            }
        },

        getLineItemsArray() {
            if (!this.order?.lineItems) return [];

            if (Array.isArray(this.order.lineItems)) {
                return this.order.lineItems;
            }

            if (this.order.lineItems.elements) {
                return Object.values(this.order.lineItems.elements);
            }

            return [];
        },

        extractBundleDataForLineItem(lineItem) {
            if (!lineItem?.id || lineItem.type !== 'custom') return;

            try {
                const payload = this.parsePayload(lineItem.payload);
                if (!payload?.isBundle) return;

                const bundleData = this.createBundleData(payload, lineItem);
                this.lineItemBundleData.set(lineItem.id, bundleData);
            } catch (error) {
                this.handleError(`Error extracting bundle data for line item: ${lineItem.id}`, error);
            }
        },

        parsePayload(payload) {
            if (typeof payload === 'string') {
                return JSON.parse(payload);
            }
            return payload;
        },

        createBundleData(payload, lineItem) {
            return {
                bundleId: payload.bundleId || null,
                bundleDescription: payload.bundleDescription || 'Bundle',
                mainProductName: payload.mainProductName || '',
                mainProductId: payload.mainProductId || payload.mainProductNumber || null,
                totalProductCount: this.parseNumber(payload.totalProductCount),
                originalPrice: this.parseNumber(payload.originalPrice),
                finalPrice: this.parseNumber(payload.finalPrice),
                savings: this.parseNumber(payload.savings),
                discountValue: this.parseNumber(payload.discountValue),
                discountType: payload.discountType || 'percentage',
                bundleProducts: this.normalizeBundleProducts(payload.bundleProducts),
                allBundleProducts: this.normalizeBundleProducts(payload.allBundleProducts),
                lineItemId: lineItem.id,
                lineItemPrice: this.parseNumber(lineItem.price),
                lineItemTotalPrice: this.parseNumber(lineItem.totalPrice),
                lineItemQuantity: this.parseNumber(lineItem.quantity),
                lineItemLabel: lineItem.label || ''
            };
        },

        parseNumber(value) {
            return parseFloat(value) || 0;
        },

        normalizeBundleProducts(products) {
            if (!products) return [];

            if (Array.isArray(products)) {
                return products.map(product => this.normalizeProductData(product));
            }

            if (typeof products === 'object') {
                const keys = Object.keys(products);
                const numericKeys = keys.filter(key => /^\d+$/.test(key));

                if (numericKeys.length > 0) {
                    return Object.values(products).map(product => this.normalizeProductData(product));
                }

                if (products.productName || products.name || products.label) {
                    return [this.normalizeProductData(products)];
                }

                return Object.values(products).map(product => this.normalizeProductData(product));
            }

            if (typeof products === 'string') {
                try {
                    const parsed = JSON.parse(products);
                    return this.normalizeBundleProducts(parsed);
                } catch {
                    return [];
                }
            }

            return [];
        },

        normalizeProductData(product) {
            if (!product || typeof product !== 'object') return product;

            const productNumber = product.productNumber || product.number ||
                product.sku || product.productSku ||
                product.article || product.articleNumber ||
                product.productCode || product.code || null;

            const name = product.name || product.productName ||
                product.label || product.title || null;

            const unitPrice = this.parseNumber(product.unitPrice || product.price || product.singlePrice);
            const quantity = this.parseNumber(product.quantity || product.qty || product.amount) || 1;

            return {
                ...product,
                productNumber,
                number: productNumber,
                sku: productNumber,
                name,
                productName: name,
                unitPrice,
                price: unitPrice,
                totalPrice: this.parseNumber(product.totalPrice) || (unitPrice * quantity),
                quantity
            };
        },

        isBundleLineItem(lineItem) {
            return lineItem && this.lineItemBundleData.has(lineItem.id);
        },

        getBundleDataForLineItem(lineItem) {
            return lineItem?.id ? this.lineItemBundleData.get(lineItem.id) || null : null;
        },

        showBundleDetails(lineItem) {
            if (!lineItem) return;

            this.closeBundleDetailsModal();

            const bundleData = this.getBundleDataForLineItem(lineItem);
            this.selectedBundleDetails = bundleData ?
                this.createPlainBundleData(bundleData, lineItem) :
                this.createFallbackBundleData(lineItem);

            this.showBundleDetailsModal = true;
        },

        createPlainBundleData(bundleData, lineItem) {
            const lineItemPrice = this.parseNumber(bundleData.lineItemPrice || lineItem.price);
            const lineItemTotalPrice = this.parseNumber(bundleData.lineItemTotalPrice || lineItem.totalPrice);

            return {
                bundleDescription: bundleData.bundleDescription || 'Bundle',
                mainProductName: bundleData.mainProductName || lineItem.label || 'Unknown Product',
                totalProductCount: bundleData.totalProductCount || 0,
                lineItemQuantity: bundleData.lineItemQuantity || lineItem.quantity || 1,
                originalPrice: bundleData.originalPrice || 0,
                finalPrice: bundleData.finalPrice || lineItemPrice || 0,
                lineItemPrice,
                lineItemTotalPrice,
                savings: bundleData.savings || 0,
                discountValue: bundleData.discountValue || 0,
                discountType: bundleData.discountType || 'percentage',
                bundleProducts: bundleData.bundleProducts || [],
                allBundleProducts: bundleData.allBundleProducts || [],
                bundleId: bundleData.bundleId,
                lineItemId: lineItem.id,
                lineItemLabel: lineItem.label || ''
            };
        },

        createFallbackBundleData(lineItem) {
            return {
                bundleDescription: 'Bundle Information Not Available',
                mainProductName: lineItem.label || 'Unknown Product',
                totalProductCount: 0,
                lineItemQuantity: lineItem.quantity || 1,
                originalPrice: lineItem.price || 0,
                finalPrice: lineItem.price || 0,
                lineItemPrice: lineItem.price || 0,
                lineItemTotalPrice: lineItem.totalPrice || 0,
                savings: 0,
                discountValue: 0,
                discountType: 'percentage',
                bundleProducts: [],
                allBundleProducts: [],
                bundleId: null,
                lineItemId: lineItem.id,
                lineItemLabel: lineItem.label || ''
            };
        },

        closeBundleDetailsModal() {
            this.$set(this, 'showBundleDetailsModal', false);
            this.$set(this, 'selectedBundleDetails', null);
        },

        formatCurrency(amount) {
            const numericAmount = typeof amount === 'number' ? amount : this.parseNumber(amount);
            return `€${numericAmount.toFixed(2)}`;
        },

        formatDiscount(discountValue, discountType) {
            return discountType === 'percentage' ?
                `${discountValue}%` :
                this.formatCurrency(discountValue);
        },

        getProductDisplayName(product) {
            return product.productName || product.name ||
                product.label || product.title || 'Unknown Product';
        },

        getProductQuantity(product) {
            return product.quantity || product.qty || product.amount || 1;
        },

        getProductPrice(product) {
            return this.parseNumber(product.price || product.unitPrice || product.singlePrice);
        },

        calculateProductTotal(product) {
            return this.getProductPrice(product) * this.getProductQuantity(product);
        },

        isMainProduct(product) {
            if (!product || !this.selectedBundleDetails) return false;

            const productName = (product.name || product.productName || '').toLowerCase();
            const mainProductName = (this.selectedBundleDetails.mainProductName || '').toLowerCase();

            if (productName && mainProductName && productName === mainProductName) {
                return true;
            }

            if (product.id && this.selectedBundleDetails.mainProductId &&
                product.id === this.selectedBundleDetails.mainProductId) {
                return true;
            }

            if (this.selectedBundleDetails.bundleProducts?.length > 0) {
                const isBundleProduct = this.selectedBundleDetails.bundleProducts.some(bundleProduct => {
                    const bundleName = (bundleProduct.name || bundleProduct.productName || '').toLowerCase();
                    return bundleName && productName && bundleName === productName;
                });

                if (isBundleProduct) return false;
            }

            if (this.selectedBundleDetails.allBundleProducts?.length > 0) {
                const firstProduct = this.selectedBundleDetails.allBundleProducts[0];
                const firstProductName = (firstProduct.name || firstProduct.productName || '').toLowerCase();
                return firstProductName && productName && firstProductName === productName;
            }

            return false;
        },

        handleError(message, error) {
            // Optionally implement error reporting to external service
            // For now, fail silently to maintain user experience
        }
    }
});