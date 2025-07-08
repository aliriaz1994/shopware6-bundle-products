import template from './digipercep-bundle-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria, EntityCollection } = Shopware.Data;

Component.register('digipercep-bundle-detail', {
    template,

    inject: ['repositoryFactory', 'loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            bundle: {
                name: '',
                discount: 0,
                discountType: 'percentage',
                active: true,
                priority: 0
            },
            bundleProducts: [],
            selectedProductToAdd: null,
            selectedProductsForAdd: [],
            isLoading: false,
            isProductsLoading: false,
            isSaveSuccessful: false
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
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addFilter(Criteria.equals('parentId', null));

            const excludeIds = this.bundleProducts.map(bp => bp.productId);
            const selectedIds = this.selectedProductsForAdd.map(p => p.id);
            const allExcludeIds = [...excludeIds, ...selectedIds];

            if (allExcludeIds.length > 0) {
                criteria.addFilter(Criteria.not('AND', [
                    Criteria.equalsAny('id', allExcludeIds)
                ]));
            }

            criteria.setLimit(25);
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

    created() {
        this.loadBundle();
    },

    methods: {
        async loadBundle() {
            if (this.isCreateMode) {
                this.bundle = {
                    name: '',
                    discount: 0,
                    discountType: 'percentage',
                    isSelectable: false,
                    active: true,
                    priority: 0
                };
                this.bundleProducts = [];
                this.isProductsLoading = false;
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
                this.createNotificationError({
                    message: `Failed to load bundle: ${error.message}`
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadBundleProducts() {
            if (!this.bundle.id) {
                this.bundleProducts = [];
                this.isProductsLoading = false;
                return;
            }

            this.isProductsLoading = true;

            try {
                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('bundleId', this.bundle.id));
                criteria.addAssociation('product.cover');
                criteria.addAssociation('product.prices');
                criteria.addSorting(Criteria.sort('position', 'ASC'));

                const result = await this.bundleProductRepository.search(criteria, Shopware.Context.api);
                this.bundleProducts = Array.from(result);
            } catch (error) {
                this.createNotificationError({
                    message: `Error loading products: ${error.message}`
                });
                this.bundleProducts = [];
            } finally {
                this.isProductsLoading = false;
            }
        },

        async onProductSelectedFromDropdown(productId) {
            if (!productId) {
                return;
            }

            try {
                const product = await this.productRepository.get(productId, Shopware.Context.api);

                const existsInBundle = this.bundleProducts.find(bp => bp.productId === productId);
                if (existsInBundle) {
                    this.createNotificationWarning({
                        message: 'This product is already in the bundle'
                    });
                    this.selectedProductToAdd = null;
                    return;
                }

                const alreadySelected = this.selectedProductsForAdd.find(p => p.id === productId);
                if (alreadySelected) {
                    this.createNotificationWarning({
                        message: 'This product is already selected'
                    });
                    this.selectedProductToAdd = null;
                    return;
                }

                this.selectedProductsForAdd.push(product);
                this.selectedProductToAdd = null;

                this.createNotificationInfo({
                    message: `${product.name} added to selection`
                });

            } catch (error) {
                this.createNotificationError({
                    message: `Error loading product: ${error.message}`
                });
            }
        },

        removeFromSelection(product) {
            const index = this.selectedProductsForAdd.findIndex(p => p.id === product.id);
            if (index > -1) {
                this.selectedProductsForAdd.splice(index, 1);
                this.createNotificationInfo({
                    message: `${product.name} removed from selection`
                });
            }
        },

        clearSelection() {
            this.selectedProductsForAdd = [];
            this.selectedProductToAdd = null;
            this.createNotificationInfo({
                message: 'Selection cleared'
            });
        },

        async addSelectedProducts() {
            if (this.selectedProductsForAdd.length === 0) {
                this.createNotificationWarning({
                    message: 'No products selected'
                });
                return;
            }

            if (!this.bundle.id) {
                this.createNotificationError({
                    message: 'Bundle must be saved before adding products'
                });
                return;
            }

            this.isProductsLoading = true;

            try {
                const bundleProductEntities = [];
                const nextPosition = this.bundleProducts.length;

                for (let i = 0; i < this.selectedProductsForAdd.length; i++) {
                    const product = this.selectedProductsForAdd[i];

                    const bundleProductEntity = this.bundleProductRepository.create(Shopware.Context.api);

                    bundleProductEntity.bundleId = this.bundle.id;
                    bundleProductEntity.productId = product.id;
                    bundleProductEntity.quantity = 1;
                    bundleProductEntity.position = nextPosition + i + 1;

                    bundleProductEntities.push(bundleProductEntity);
                }

                await this.bundleProductRepository.saveAll(bundleProductEntities, Shopware.Context.api);

                this.createNotificationSuccess({
                    message: `Successfully added ${bundleProductEntities.length} product(s) to bundle`
                });

                this.selectedProductsForAdd = [];
                this.selectedProductToAdd = null;

                await this.loadBundleProducts();

            } catch (error) {
                this.createNotificationError({
                    message: `Error adding products: ${error.message}`
                });
            } finally {
                this.isProductsLoading = false;
            }
        },

        async removeProduct(bundleProduct) {
            if (!bundleProduct.id) {
                this.createNotificationError({
                    message: 'Cannot remove product: Invalid product ID'
                });
                return;
            }

            try {
                await this.bundleProductRepository.delete(bundleProduct.id, Shopware.Context.api);

                this.createNotificationSuccess({
                    message: 'Product removed from bundle'
                });

                await this.loadBundleProducts();
            } catch (error) {
                this.createNotificationError({
                    message: `Error removing product: ${error.message}`
                });
            }
        },

        async onProductUpdate(item) {
            try {
                await this.bundleProductRepository.save(item, Shopware.Context.api);

                this.createNotificationSuccess({
                    message: 'Product updated successfully'
                });
            } catch (error) {
                this.createNotificationError({
                    message: `Error updating product: ${error.message}`
                });
                await this.loadBundleProducts();
            }
        },

        async onSave() {
            if (!this.bundle.name || this.bundle.name.trim() === '') {
                this.createNotificationError({
                    message: 'Bundle name is required'
                });
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
                    const bundleEntity = this.bundleRepository.create(Shopware.Context.api);
                    Object.assign(bundleEntity, bundleData);

                    await this.bundleRepository.save(bundleEntity, Shopware.Context.api);

                    this.bundle.id = bundleEntity.id;

                    this.createNotificationSuccess({
                        message: 'Bundle created successfully'
                    });
                    this.isSaveSuccessful = true;

                    this.$router.push({
                        name: 'digipercep.bundle.detail',
                        params: { id: bundleEntity.id }
                    });
                } else {
                    const bundleEntity = await this.bundleRepository.get(this.bundle.id, Shopware.Context.api);
                    Object.assign(bundleEntity, bundleData);

                    await this.bundleRepository.save(bundleEntity, Shopware.Context.api);

                    this.createNotificationSuccess({
                        message: 'Bundle updated successfully'
                    });
                    this.isSaveSuccessful = true;
                }
            } catch (error) {
                this.createNotificationError({
                    message: `Save failed: ${error.message}`
                });
            } finally {
                this.isLoading = false;
            }
        },

        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onCancel() {
            this.$router.push({ name: 'digipercep.bundle.list' });
        }
    }
});