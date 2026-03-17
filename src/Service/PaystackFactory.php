<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Service;

use Kommandhub\Paystack\Paystack;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

readonly class PaystackFactory
{
    public function __construct(
        private Config $config,
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory
    ) {
    }

    /**
     * Creates a new instance of the Paystack client.
     *
     * @param string|null $salesChannelId The sales channel ID to fetch configuration for.
     *
     * @return Paystack The configured Paystack client.
     */
    public function create(?string $salesChannelId = null): Paystack
    {
        // 1. Determine if sandbox mode is enabled.
        $sandbox = $this->config->getBool('enableSandbox', $salesChannelId);

        // 2. Retrieve the appropriate secret key based on the environment (Live vs Sandbox).
        $secretKey = $sandbox
            ? $this->config->getString('apiSecretKeySandbox', $salesChannelId)
            : $this->config->getString('apiSecretKey', $salesChannelId);

        // 3. Instantiate the Paystack client with PSR-18 and PSR-17 dependencies.
        return new Paystack(
            $secretKey,
            null,
            $this->client,
            $this->requestFactory,
            $this->streamFactory
        );
    }
}
