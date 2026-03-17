<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Service;

use Kommandhub\Paystack\Paystack;
use Kommandhub\PaystackSW\Service\Config;
use Kommandhub\PaystackSW\Service\PaystackFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class PaystackFactoryTest extends TestCase
{
    private Config $config;
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private PaystackFactory $paystackFactory;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->client = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->paystackFactory = new PaystackFactory(
            $this->config,
            $this->client,
            $this->requestFactory,
            $this->streamFactory
        );
    }

    public function testCreateLiveMode(): void
    {
        $this->config->method('getBool')->with('enableSandbox', 'sales-channel-id')->willReturn(false);
        $this->config->method('get')->with('apiSecretKey', '', 'sales-channel-id')->willReturn('live_secret_key');

        $paystack = $this->paystackFactory->create('sales-channel-id');

        $this->assertInstanceOf(Paystack::class, $paystack);

        // We can't easily verify the internal state of Paystack without reflection or exposing it,
        // but the factory logic itself is tested by ensuring it uses the correct keys.
    }

    public function testCreateSandboxMode(): void
    {
        $this->config->method('getBool')->with('enableSandbox', 'sales-channel-id')->willReturn(true);
        $this->config->method('get')->with('apiSecretKeySandbox', '', 'sales-channel-id')->willReturn('sandbox_secret_key');

        $paystack = $this->paystackFactory->create('sales-channel-id');

        $this->assertInstanceOf(Paystack::class, $paystack);
    }
}
