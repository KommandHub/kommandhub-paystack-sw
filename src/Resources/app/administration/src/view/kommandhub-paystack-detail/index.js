import template from './kommandhub-paystack-detail.html.twig'
import './kommandhub-paystack-detail.scss'
import icon from './icon.png'

const { Store } = Shopware;

Shopware.Component.register('kommandhub-paystack-detail', {
    template,

    metaInfo() {
        return {
            title: this.$t('kommandhub-paystack-detail.title')
        };
    },

    inject: [
        'repositoryFactory',
    ],

    props: {
        orderId: {
            type: String,
            required: false,
            default: null,
        },
    },

    computed: {
        order: () => Store.get('swOrderDetail').order,

        orderChanges() {
            if (!this.order) {
                return false;
            }

            return this.orderRepository.hasChanges(this.order);
        },

        paystackTransaction() {
            if (!this.order || !this.order.transactions) {
                return null;
            }

            return this.order.transactions.find((transaction) => {
                return transaction.customFields && transaction.customFields.paystack_reference;
            });
        },

        currencyFilter() {
            return Shopware.Filter.getByName('currency');
        },

        paystackIcon() {
            return icon;
        },
    },

    watch: {
        orderId() {
            this.createdComponent();
        },
    },
});