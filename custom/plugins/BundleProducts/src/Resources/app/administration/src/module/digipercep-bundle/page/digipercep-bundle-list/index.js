import template from './digipercep-bundle-list.html.twig';
import './digipercep-bundle-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('digipercep-bundle-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            bundles: [],
            isLoading: true,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            total: 0,
            term: null,
            page: 1,
            limit: 25
        };
    },

    computed: {
        bundleRepository() {
            return this.repositoryFactory.create('digipercep_bundle');
        },

        columns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: 'Name',
                    routerLink: 'digipercep.bundle.detail',
                    primary: true,
                    allowResize: true,
                    align: 'center'
                },
                {
                    property: 'discount',
                    dataIndex: 'discount',
                    label: 'Discount',
                    allowResize: true,
                    align: 'center'
                },
                {
                    property: 'active',
                    dataIndex: 'active',
                    label: 'Active',
                    allowResize: true,
                    align: 'center'
                },
                {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: 'Created At',
                    allowResize: true,
                    align: 'center'
                }
            ];
        },

        listCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            if (this.sortBy) {
                criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            }

            if (this.term) {
                criteria.setTerm(this.term);
            }

            return criteria;
        }
    },

    metaInfo() {
        return {
            title: this.$createTitle('Bundle Products')
        };
    },

    created() {
        this.loadBundles();
    },

    methods: {
        async loadBundles() {
            this.isLoading = true;

            try {
                const result = await this.bundleRepository.search(this.listCriteria, Shopware.Context.api);
                this.bundles = Array.from(result);
                this.total = result.total || 0;
            } catch (error) {
                this.createNotificationError({
                    message: `Error loading bundles: ${error.message}`
                });
                this.bundles = [];
                this.total = 0;
            } finally {
                this.isLoading = false;
            }
        },

        formatDate(dateString) {
            if (!dateString) return '-';

            try {
                const date = new Date(dateString);
                if (isNaN(date.getTime())) return dateString;

                return date.toLocaleDateString('en-GB', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (error) {
                return dateString;
            }
        },

        onRefresh() {
            this.loadBundles();
        },

        onSearch(searchTerm) {
            this.term = searchTerm;
            this.page = 1;
            this.loadBundles();
        },

        onPageChange(opts) {
            this.page = opts.page || 1;
            this.limit = opts.limit || 25;
            this.loadBundles();
        },

        onSortColumn(column) {
            if (this.sortBy === column.dataIndex) {
                this.sortDirection = this.sortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sortBy = column.dataIndex;
                this.sortDirection = 'ASC';
            }
            this.page = 1;
            this.loadBundles();
        },

        async onDeleteItem(bundleId) {
            try {
                await this.bundleRepository.delete(bundleId, Shopware.Context.api);
                this.createNotificationSuccess({ message: 'Bundle deleted successfully' });
                this.loadBundles();
            } catch (error) {
                this.createNotificationError({
                    message: `Failed to delete bundle: ${error.message}`
                });
            }
        }
    }
});