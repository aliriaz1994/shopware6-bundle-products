import template from './digipercep-bundle-detail-products.html.twig';

const { Component, Mixin } = Shopware;

Component.register('digipercep-bundle-detail-products', {
    template,

    inject: ['loginService'],

    mixins: [Mixin.getByName('notification')],

    props: {
        bundle: {
            type: Object,
            required: true
        }
    },

    data() {
        return {
            bundleProducts: [],
            searchTerm: '',
            searchResults: [],
            selectedProductsForAdd: [],
            isLoading: false,
            searchTimeout: null
        };
    },

    computed: {
        productColumns() {
            return [
                {
                    property: 'product',
                    dataIndex: 'product.name',
                    label: 'Product',
                    primary: true,
                    allowResize: true,
                    sortable: false
                },
                {
                    property: 'quantity',
                    dataIndex: 'quantity',
                    label: 'Quantity',
                    allowResize: true,
                    inlineEdit: 'number',
                    align: 'center'
                },
                {
                    property: 'isOptional',
                    dataIndex: 'isOptional',
                    label: 'Optional',
                    allowResize: true,
                    inlineEdit: 'boolean',
                    align: 'center'
                }
            ];
        }
    },

    watch: {
        bundle: {
            handler() {
                this.loadBundleProducts();
            },
            immediate: true
        }
    },

    methods: {
        async loadBundleProducts() {
            if (!this.bundle?.id) {
                this.bundleProducts = [];
                return;
            }

            this.isLoading = true;

            try {
                const response = await fetch(`/api/digipercep-bundle/${this.bundle.id}/products`, {
                    method: 'GET',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    }
                });

                if (response.ok) {
                    const result = await response.json();
                    this.bundleProducts = result.data || [];
                } else {
                    throw new Error('Failed to load bundle products');
                }
            } catch (error) {
                console.error('Error loading bundle products:', error);
                this.createNotificationError({
                    message: `Error loading products: ${error.message}`
                });
                this.bundleProducts = [];
            } finally {
                this.isLoading = false;
            }
        },

        onSearchProducts(searchTerm) {
            this.searchTerm = searchTerm;

            // Clear previous timeout
            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
            }

            // Debounce search
            this.searchTimeout = setTimeout(() => {
                if (searchTerm && searchTerm.length >= 2) {
                    this.searchProducts(searchTerm);
                } else {
                    this.searchResults = [];
                }
            }, 300);
        },

        async searchProducts(term) {
            try {
                // Use Shopware's search API directly
                const response = await fetch('/api/search/product', {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        limit: 10,
                        page: 1,
                        term: term,
                        associations: {
                            cover: {
                                associations: {
                                    media: {}
                                }
                            }
                        },
                        filter: [
                            {
                                type: 'equals',
                                field: 'active',
                                value: true
                            },
                            {
                                type: 'equals',
                                field: 'parentId',
                                value: null
                            }
                        ]
                    })
                });

                if (response.ok) {
                    const result = await response.json();
                    this.searchResults = result.data || [];
                } else {
                    console.error('Product search failed');
                    this.searchResults = [];
                }
            } catch (error) {
                console.error('Error searching products:', error);
                this.searchResults = [];
            }
        },

        selectProduct(product) {
            // Check if product already exists in bundle
            const existsInBundle = this.bundleProducts.find(bp => bp.productId === product.id);
            if (existsInBundle) {
                this.createNotificationWarning({
                    message: 'This product is already in the bundle'
                });
                return;
            }

            // Check if product already selected for adding
            const alreadySelected = this.selectedProductsForAdd.find(p => p.id === product.id);
            if (alreadySelected) {
                this.createNotificationWarning({
                    message: 'This product is already selected'
                });
                return;
            }

            // Add to selection
            this.selectedProductsForAdd.push(product);

            // Clear search
            this.searchTerm = '';
            this.searchResults = [];

            this.createNotificationInfo({
                message: `${product.name} added to selection`
            });
        },

        clearSelection() {
            this.selectedProductsForAdd = [];
            this.searchTerm = '';
            this.searchResults = [];
        },

        async addSelectedProducts() {
            if (this.selectedProductsForAdd.length === 0) {
                return;
            }

            if (!this.bundle.id) {
                this.createNotificationError({
                    message: 'Please save the bundle first before adding products'
                });
                return;
            }

            this.isLoading = true;

            try {
                const productsToAdd = this.selectedProductsForAdd.map(product => ({
                    productId: product.id,
                    quantity: 1,
                    isOptional: false
                }));

                const response = await fetch(`/api/digipercep-bundle/${this.bundle.id}/products/bulk`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        products: productsToAdd
                    })
                });

                if (response.ok) {
                    const result = await response.json();
                    this.createNotificationSuccess({
                        message: `Added ${result.data.created} products to bundle`
                    });

                    // Clear selection and reload products
                    this.clearSelection();
                    await this.loadBundleProducts();
                } else {
                    const error = await response.json();
                    throw new Error(error.errors?.[0]?.detail || 'Failed to add products');
                }
            } catch (error) {
                console.error('Error adding products:', error);
                this.createNotificationError({
                    message: `Error adding products: ${error.message}`
                });
            } finally {
                this.isLoading = false;
            }
        },

        async removeProduct(bundleProduct) {
            if (!bundleProduct.id) {
                this.createNotificationError({
                    message: 'Invalid product data'
                });
                return;
            }

            try {
                const response = await fetch(`/api/digipercep-bundle/products/${bundleProduct.id}`, {
                    method: 'DELETE',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    }
                });

                if (response.ok) {
                    this.createNotificationSuccess({
                        message: 'Product removed from bundle'
                    });

                    await this.loadBundleProducts();
                } else {
                    const error = await response.json();
                    throw new Error(error.errors?.[0]?.detail || 'Failed to remove product');
                }
            } catch (error) {
                console.error('Error removing product:', error);
                this.createNotificationError({
                    message: `Error removing product: ${error.message}`
                });
            }
        },

        async onInlineEditSave(item) {
            if (!item.id) {
                this.createNotificationError({
                    message: 'Invalid product data'
                });
                return;
            }

            try {
                const response = await fetch(`/api/digipercep-bundle/products/${item.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Authorization': `Bearer ${this.loginService.getToken()}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        quantity: parseInt(item.quantity) || 1,
                        isOptional: Boolean(item.isOptional)
                    })
                });

                if (response.ok) {
                    this.createNotificationSuccess({
                        message: 'Product updated successfully'
                    });
                } else {
                    const error = await response.json();
                    throw new Error(error.errors?.[0]?.detail || 'Failed to update product');
                }
            } catch (error) {
                console.error('Error updating product:', error);
                this.createNotificationError({
                    message: `Error updating product: ${error.message}`
                });
                await this.loadBundleProducts();
            }
        }
    }
});