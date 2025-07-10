import template from './digipercep-bundle-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('digipercep-bundle-detail', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            bundle: this.getDefaultBundle(),
            bundleProducts: [],
            selectedProductToAdd: null,
            selectedProductsForAdd: [],
            isLoading: false,
            isProductsLoading: false,
            isSaveSuccessful: false,
            parentProductNames: new Map()
        };
    },

    computed: {
        bundleId() {
            return this.$route?.params?.id || null;
        },

        isCreateMode() {
            return !this.bundleId || this.bundleId === 'create';
        },

        bundleRepository() {
            return this.repositoryFactory.create('digipercep_bundle');
        },

        bundleProductRepository() {
            return this.repositoryFactory.create('digipercep_bundle_product');
        },

        productRepository() {
            return this.repositoryFactory.create('product');
        },

        productCriteria() {
            const criteria = new Criteria();
            criteria.addAssociation('cover');
            criteria.addAssociation('options.group');
            criteria.addFilter(Criteria.equals('active', true));

            const excludedIds = [
                ...this.bundleProducts.map(bp => bp.productId),
                ...this.selectedProductsForAdd.map(p => p.id)
            ];

            if (excludedIds.length > 0) {
                criteria.addFilter(Criteria.not('AND', [
                    Criteria.equalsAny('id', excludedIds)
                ]));
            }

            criteria.addSorting(Criteria.sort('parentId', 'ASC', true));
            criteria.addSorting(Criteria.sort('productNumber', 'ASC'));
            criteria.setLimit(50);

            return criteria;
        },

        discountTypeOptions() {
            return [
                { label: 'Percentage', value: 'percentage' },
                { label: 'Absolute', value: 'absolute' }
            ];
        },

        productColumns() {
            return [
                {
                    property: 'product',
                    dataIndex: 'product.name',
                    label: 'Product',
                    primary: true,
                    allowResize: true
                },
                {
                    property: 'productNumber',
                    dataIndex: 'product.productNumber',
                    label: 'Product Number',
                    allowResize: true,
                    align: 'center'
                },
                {
                    property: 'quantity',
                    dataIndex: 'quantity',
                    label: 'Quantity',
                    allowResize: true,
                    inlineEdit: 'number',
                    align: 'center'
                }
            ];
        }
    },

    async created() {
        await Promise.all([
            this.loadBundle(),
            this.loadParentProductNames()
        ]);
    },

    methods: {
        getDefaultBundle() {
            return {
                name: '',
                discount: 0,
                discountType: 'percentage',
                active: true,
                priority: 0
            };
        },

        async loadParentProductNames() {
            try {
                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('active', true));
                criteria.addFilter(Criteria.equals('parentId', null));
                criteria.setLimit(500);

                const result = await this.productRepository.search(criteria, Shopware.Context.api);

                this.parentProductNames = new Map(
                    result.map(parent => [parent.id, parent.name])
                );
            } catch (error) {
                console.error('Error loading parent product names:', error);
            }
        },

        async loadBundle() {
            if (this.isCreateMode) {
                this.bundle = this.getDefaultBundle();
                this.bundleProducts = [];
                return;
            }

            this.isLoading = true;

            try {
                const bundle = await this.bundleRepository.get(this.bundleId, Shopware.Context.api);
                this.bundle = {
                    id: bundle.id,
                    name: bundle.name || '',
                    discount: bundle.discount || 0,
                    discountType: bundle.discountType || 'percentage',
                    active: Boolean(bundle.active),
                    priority: bundle.priority || 0
                };

                await this.loadBundleProducts();
            } catch (error) {
                this.showError(`Failed to load bundle: ${error.message}`);
            } finally {
                this.isLoading = false;
            }
        },

        async loadBundleProducts() {
            if (!this.bundle.id) {
                this.bundleProducts = [];
                return;
            }

            this.isProductsLoading = true;

            try {
                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('bundleId', this.bundle.id));
                criteria.addAssociation('product.cover');
                criteria.addAssociation('product.options.group');
                criteria.addAssociation('product.parent');
                criteria.addSorting(Criteria.sort('position', 'ASC'));

                const result = await this.bundleProductRepository.search(criteria, Shopware.Context.api);
                this.bundleProducts = Array.from(result);
            } catch (error) {
                this.showError(`Error loading products: ${error.message}`);
                this.bundleProducts = [];
            } finally {
                this.isProductsLoading = false;
            }
        },

        async onProductSelectedFromDropdown(productId) {
            if (!productId) return;

            try {
                const criteria = new Criteria();
                criteria.addAssociation('cover');
                criteria.addAssociation('options.group');

                const product = await this.productRepository.get(productId, Shopware.Context.api, criteria);

                if (this.isProductAlreadyInBundle(productId)) {
                    this.showWarning('This product is already in the bundle');
                    this.selectedProductToAdd = null;
                    return;
                }

                if (this.isProductAlreadySelected(productId)) {
                    this.showWarning('This product is already selected');
                    this.selectedProductToAdd = null;
                    return;
                }

                this.selectedProductsForAdd.push(product);
                this.selectedProductToAdd = null;
                this.showInfo(`${this.getProductDisplayName(product)} added to selection`);
            } catch (error) {
                this.showError(`Error loading product: ${error.message}`);
            }
        },

        isProductAlreadyInBundle(productId) {
            return this.bundleProducts.some(bp => bp.productId === productId);
        },

        isProductAlreadySelected(productId) {
            return this.selectedProductsForAdd.some(p => p.id === productId);
        },

        getProductDisplayName(product) {
            if (!product) return '';

            const productNumber = product.productNumber ? `[${product.productNumber}]` : '';
            const options = this.formatProductOptions(product.options);

            if (product.parentId) {
                const parentName = this.parentProductNames.get(product.parentId) || 'Product';
                return `└─ ${parentName} ${productNumber}${options}`;
            }

            return `${product.name || 'Unknown Product'} ${productNumber}`;
        },

        getDropdownDisplayName(product) {
            if (!product) return '';

            let displayName = product.parentId
                ? `└─ ${this.parentProductNames.get(product.parentId) || product.name || 'Variant'}`
                : product.name || 'Unknown Product';

            if (product.productNumber) {
                displayName += ` [${product.productNumber}]`;
            }

            const options = this.formatProductOptions(product.options);
            return displayName + options;
        },

        getBundleProductDisplayName(bundleProduct) {
            if (!bundleProduct?.product) return 'Unknown Product';

            const product = bundleProduct.product;

            if (product.parentId) {
                const parentName = product.parent?.name ||
                    this.parentProductNames.get(product.parentId) ||
                    product.name || 'Product';

                const options = this.formatProductOptions(product.options, true);
                return `${parentName}${options}`;
            }

            return product.name || 'Unknown Product';
        },

        formatProductOptions(options, includeGroupName = false) {
            if (!options?.length) return '';

            const optionValues = options
                .map(option => {
                    if (includeGroupName && option.group?.name) {
                        return `${option.group.name}: ${option.name}`;
                    }
                    return option.name;
                })
                .filter(Boolean);

            return optionValues.length ? ` (${optionValues.join(', ')})` : '';
        },

        removeFromSelection(product) {
            const index = this.selectedProductsForAdd.findIndex(p => p.id === product.id);
            if (index > -1) {
                this.selectedProductsForAdd.splice(index, 1);
                this.showInfo(`${this.getProductDisplayName(product)} removed from selection`);
            }
        },

        clearSelection() {
            this.selectedProductsForAdd = [];
            this.selectedProductToAdd = null;
            this.showInfo('Selection cleared');
        },

        async addSelectedProducts() {
            if (!this.selectedProductsForAdd.length) {
                this.showWarning('No products selected');
                return;
            }

            if (!this.bundle.id) {
                this.showError('Bundle must be saved before adding products');
                return;
            }

            this.isProductsLoading = true;

            try {
                const nextPosition = this.bundleProducts.length;
                const bundleProductEntities = this.selectedProductsForAdd.map((product, index) => {
                    const entity = this.bundleProductRepository.create(Shopware.Context.api);
                    entity.bundleId = this.bundle.id;
                    entity.productId = product.id;
                    entity.quantity = 1;
                    entity.position = nextPosition + index + 1;
                    return entity;
                });

                await this.bundleProductRepository.saveAll(bundleProductEntities, Shopware.Context.api);

                this.showSuccess(`Successfully added ${bundleProductEntities.length} product(s) to bundle`);
                this.selectedProductsForAdd = [];
                this.selectedProductToAdd = null;

                await this.loadBundleProducts();
            } catch (error) {
                this.showError(`Error adding products: ${error.message}`);
            } finally {
                this.isProductsLoading = false;
            }
        },

        async removeProduct(bundleProduct) {
            if (!bundleProduct.id) {
                this.showError('Cannot remove product: Invalid product ID');
                return;
            }

            try {
                await this.bundleProductRepository.delete(bundleProduct.id, Shopware.Context.api);
                this.showSuccess('Product removed from bundle');
                await this.loadBundleProducts();
            } catch (error) {
                this.showError(`Error removing product: ${error.message}`);
            }
        },

        async onProductUpdate(item) {
            try {
                await this.bundleProductRepository.save(item, Shopware.Context.api);
                this.showSuccess('Product updated successfully');
            } catch (error) {
                this.showError(`Error updating product: ${error.message}`);
                await this.loadBundleProducts();
            }
        },

        async onSave() {
            if (!this.bundle.name?.trim()) {
                this.showError('Bundle name is required');
                return;
            }

            this.isLoading = true;

            try {
                const bundleData = {
                    name: this.bundle.name.trim(),
                    discount: parseFloat(this.bundle.discount) || 0,
                    discountType: this.bundle.discountType || 'percentage',
                    active: Boolean(this.bundle.active),
                    priority: parseInt(this.bundle.priority) || 0
                };

                if (this.isCreateMode) {
                    await this.createBundle(bundleData);
                } else {
                    await this.updateBundle(bundleData);
                }

                this.isSaveSuccessful = true;
            } catch (error) {
                this.showError(`Save failed: ${error.message}`);
            } finally {
                this.isLoading = false;
            }
        },

        async createBundle(bundleData) {
            const bundleEntity = this.bundleRepository.create(Shopware.Context.api);
            Object.assign(bundleEntity, bundleData);

            await this.bundleRepository.save(bundleEntity, Shopware.Context.api);

            this.bundle.id = bundleEntity.id;
            this.showSuccess('Bundle created successfully');

            this.$router.push({
                name: 'digipercep.bundle.detail',
                params: { id: bundleEntity.id }
            });
        },

        async updateBundle(bundleData) {
            const bundleEntity = await this.bundleRepository.get(this.bundle.id, Shopware.Context.api);
            Object.assign(bundleEntity, bundleData);

            await this.bundleRepository.save(bundleEntity, Shopware.Context.api);
            this.showSuccess('Bundle updated successfully');
        },

        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onCancel() {
            this.$router.push({ name: 'digipercep.bundle.list' });
        },

        // Notification helpers for cleaner code
        showError(message) {
            this.createNotificationError({ message });
        },

        showWarning(message) {
            this.createNotificationWarning({ message });
        },

        showSuccess(message) {
            this.createNotificationSuccess({ message });
        },

        showInfo(message) {
            this.createNotificationInfo({ message });
        }
    }
});