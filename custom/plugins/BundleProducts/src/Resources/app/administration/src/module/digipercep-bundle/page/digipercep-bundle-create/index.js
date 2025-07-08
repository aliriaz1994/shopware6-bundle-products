import template from './digipercep-bundle-create.html.twig';

const { Component, Mixin } = Shopware;

Component.register('digipercep-bundle-create', {
    template,

    inject: ['repositoryFactory', 'loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            formData: {
                name: '',
                active: true,
                discount: 0,
                discountType: 'percentage',
                priority: 0
            },
            isLoading: false,
            isSaveSuccessful: false
        };
    },

    computed: {
        discountTypeOptions() {
            return [
                { label: this.$tc('digipercep-bundle.create.optionPercentage'), value: 'percentage' },
                { label: this.$tc('digipercep-bundle.create.optionAbsolute'), value: 'absolute' }
            ];
        }
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.$tc('digipercep-bundle.create.title'))
        };
    },

    methods: {
        async onSave() {
            if (!this.validateForm()) return;

            this.isLoading = true;

            try {
                const response = await fetch('/api/digipercep-bundle', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.loginService.getToken()}`
                    },
                    body: JSON.stringify(this.prepareFormData())
                });

                const result = await response.json();

                if (response.ok) {
                    this.createNotificationSuccess({
                        message: this.$tc('digipercep-bundle.create.messageSaveSuccess')
                    });
                    this.isSaveSuccessful = true;
                } else {
                    throw new Error(result.errors?.[0]?.detail || 'Unknown error');
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('digipercep-bundle.create.messageSaveError', 0, { error: error.message })
                });
            } finally {
                this.isLoading = false;
            }
        },

        validateForm() {
            if (!this.formData.name?.trim()) {
                this.createNotificationError({
                    message: this.$tc('digipercep-bundle.create.errorNameRequired')
                });
                return false;
            }
            return true;
        },

        prepareFormData() {
            return {
                name: this.formData.name.trim(),
                discount: parseFloat(this.formData.discount),
                discountType: this.formData.discountType,
                active: this.formData.active,
                priority: parseInt(this.formData.priority) || 0
            };
        },

        saveFinish() {
            this.isSaveSuccessful = false;
            this.$router.push({ name: 'digipercep.bundle.list' });
        },

        onCancel() {
            this.$router.push({ name: 'digipercep.bundle.list' });
        }
    }
});