<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

readonly class PayloadBuilder
{
    public function __construct(
        private Config $config
    ) {
    }

    /**
     * Build the transaction initialization payload for Paystack.
     *
     * @param OrderTransactionEntity $orderTransaction The Shopware order transaction entity.
     * @param PaymentTransactionStruct $transaction The payment transaction struct from Shopware.
     *
     * @return array The prepared payload for Paystack's transaction initialization API.
     *
     * @throws \RuntimeException If required, order, customer, or currency information is missing.
     */
    public function build(OrderTransactionEntity $orderTransaction, PaymentTransactionStruct $transaction): array
    {
        // 1. Retrieve the parent order entity from the transaction.
        $order = $orderTransaction->getOrder();

        if ($order === null) {
            throw new \RuntimeException('Order information is missing for the payment transaction.');
        }

        // 2. Extract customer information. Paystack requires an email for transaction initialization.
        $customer = $order->getOrderCustomer();

        if ($customer === null) {
            throw new \RuntimeException('Customer information is missing for the order.');
        }

        // 3. Get the currency details to ensure the ISO code is available.
        $currency = $order->getCurrency();

        if ($currency === null) {
            throw new \RuntimeException('Currency information is missing for the order.');
        }

        // 4. Retrieve the return URL where Paystack should redirect the user after payment.
        $returnUrl = $transaction->getReturnUrl();

        if ($returnUrl === null) {
            throw new \RuntimeException('Return URL is missing in the payment transaction struct.');
        }

        // 5. Fetch sales channel specific configuration for payment options.
        $salesChannelId = $order->getSalesChannelId();

        $paymentOptions = $this->config->get(
            key: 'paymentOptions',
            salesChannelId: $salesChannelId
        );

        // 6. Build the final payload array according to Paystack API specifications.
        // The amount is multiplied by 100 to convert from the major currency unit (e.g., Naira)
        // to the minor unit (e.g., kobo) as expected by Paystack.
        return [
            'amount' => (int)($orderTransaction->getAmount()->getTotalPrice() * 100),
            'currency' => $currency->getIsoCode(),
            'email' => $customer->getEmail(),
            'callback_url' => $returnUrl,
            'channels' => $paymentOptions,
            'metadata' => [
                'cancel_action' => $returnUrl,
            ],
        ];
    }
}
