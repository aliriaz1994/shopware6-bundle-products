import template from './digipercep-bundle-detail-base.html.twig';

const { Component } = Shopware;

Component.register('digipercep-bundle-detail-base', {
    template,

    props: {
        bundle: {
            type: Object,
            required: true
        },

        formData: {
            type: Object,
            required: true
        }
    },

    computed: {
        discountTypeOptions() {
            return [
                {
                    label: this.$tc('digipercep-bundle.detail.base.optionPercentage'),
                    value: 'percentage'
                },
                {
                    label: this.$tc('digipercep-bundle.detail.base.optionAbsolute'),
                    value: 'absolute'
                }
            ];
        },

        displayModeOptions() {
            return [
                {
                    label: this.$tc('digipercep-bundle.detail.base.optionDisplayDefault'),
                    value: 'default'
                },
                {
                    label: this.$tc('digipercep-bundle.detail.base.optionDisplayPromotionBox'),
                    value: 'promotion_box'
                }
            ];
        }
    }
});