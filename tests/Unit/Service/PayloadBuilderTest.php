<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Service;

use Kommandhub\PaystackSW\Service\Config;
use Kommandhub\PaystackSW\Service\PayloadBuilder;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\System\Currency\CurrencyEntity;

class PayloadBuilderTest extends TestCase
{
    private Config $config;
    private PayloadBuilder $payloadBuilder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->payloadBuilder = new PayloadBuilder($this->config);
    }

    public function testBuildSuccessful(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);
        $amount = $this->createMock(CalculatedPrice::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $orderTransaction->method('getAmount')->willReturn($amount);
        $amount->method('getTotalPrice')->willReturn(100.50);

        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $customer->method('getEmail')->willReturn('test@example.com');
        $currency->method('getIsoCode')->willReturn('NGN');

        $transaction->method('getReturnUrl')->willReturn('https://return.url');

        $this->config->method('get')->with('paymentOptions', null, 'sales-channel-id')->willReturn(['card', 'bank']);

        $payload = $this->payloadBuilder->build($orderTransaction, $transaction);

        $expectedPayload = [
            'amount' => 10050,
            'currency' => 'NGN',
            'email' => 'test@example.com',
            'callback_url' => 'https://return.url',
            'channels' => ['card', 'bank'],
            'metadata' => [
                'cancel_action' => 'https://return.url',
            ],
        ];

        $this->assertEquals($expectedPayload, $payload);
    }

    public function testBuildThrowsExceptionWhenOrderIsMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);

        $orderTransaction->method('getOrder')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order information is missing for the payment transaction.');

        $this->payloadBuilder->build($orderTransaction, $transaction);
    }

    public function testBuildThrowsExceptionWhenCustomerIsMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Customer information is missing for the order.');

        $this->payloadBuilder->build($orderTransaction, $transaction);
    }

    public function testBuildThrowsExceptionWhenCurrencyIsMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Currency information is missing for the order.');

        $this->payloadBuilder->build($orderTransaction, $transaction);
    }

    public function testBuildThrowsExceptionWhenReturnUrlIsMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);

        $transaction->method('getReturnUrl')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Return URL is missing in the payment transaction struct.');

        $this->payloadBuilder->build($orderTransaction, $transaction);
    }
}
