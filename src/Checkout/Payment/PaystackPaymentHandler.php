<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Checkout\Payment;

use Kommandhub\Paystack\Exceptions\PaystackException;
use Kommandhub\PaystackSW\Service\Config;
use Kommandhub\PaystackSW\Service\PayloadBuilder;
use Kommandhub\PaystackSW\Service\TransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PaystackPaymentHandler is responsible for handling payment transactions with Paystack in a Shopware environment.
 *
 * This class implements the necessary methods to initialize payments, handle callbacks from Paystack, and update
 * the order transaction state accordingly. It also manages custom fields to store Paystack transaction details.
 */
class PaystackPaymentHandler extends AbstractPaymentHandler
{
    public const FIELD_REFERENCE = 'paystack_reference';
    public const FIELD_TRANSACTION_ID = 'paystack_transaction_id';
    public const FIELD_PAYMENT_TYPE = 'paystack_payment_type';
    public const FIELD_TRANSACTION_FEE = 'paystack_transaction_fee';
    public const FIELD_AMOUNT = 'paystack_amount';
    public const FIELD_CURRENCY = 'paystack_currency';
    public const FIELD_VERIFIED_AT = 'paystack_verified_at';

    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly TransactionService $transactionService,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        // 1. Retrieve the full order transaction entity including associated order and customer data.
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);

        try {
            // 2. Prepare the payment payload for Paystack initialization.
            $payload = $this->payloadBuilder->build($orderTransaction, $transaction);
        } catch (\RuntimeException $e) {
            // Handle cases where required order data might be missing.
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->error($e->getMessage(), [
                    'transaction_id' => $transaction->getOrderTransactionId(),
                ]);
            }

            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                $e->getMessage()
            );
        }

        try {
            // 3. Initialize the transaction with Paystack API.
            $response = $this->transactionService->initialize($payload);

            // 4. Validate the response status. Status 'true' indicates successful initialization.
            if (($response['status'] ?? '') !== true) {
                throw PaymentException::asyncProcessInterrupted(
                    $transaction->getOrderTransactionId(),
                    'Failed to initialize payment with Paystack: ' . ($response['message'] ?? 'Unknown error')
                );
            }

            // Log initialization success if debugging is enabled.
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->info('Payment initialized with Paystack', [
                    'transaction_id' => $transaction->getOrderTransactionId(),
                    'paystack_response' => $response,
                ]);
            }

            // 5. Redirect the customer to Paystack's hosted checkout page.
            if (!isset($response['data']['authorization_url'])) {
                throw PaymentException::asyncProcessInterrupted(
                    $transaction->getOrderTransactionId(),
                    'Failed to initialize payment with Paystack: authorization_url is missing'
                );
            }

            return new RedirectResponse($response['data']['authorization_url']);
        } catch (PaystackException $e) {
            // Handle network errors or API-specific failures.
            // @codeCoverageIgnoreStart
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->error('Error initializing payment with Paystack', [
                    'transaction_id' => $transaction->getOrderTransactionId(),
                    'error_message' => $e->getMessage(),
                ]);
            }
            // @codeCoverageIgnoreEnd
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
            );
        }
    }

    /**
     * Finalizes the payment process after returning from the payment gateway.
     *
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     *
     * @return void
     *
     * @throws PaystackException
     */
    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        // 1. Retrieve the order transaction.
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);

        // 2. Extract query parameters from the callback URL (e.g., 'reference').
        $queryParams = $request->query->all();

        // 3. Verify the payment status with Paystack's verification endpoint using the reference.
        $verificationResult = $this->verifyPayment($orderTransaction, $queryParams);

        // 4. Update the order transaction state and store relevant transaction metadata.
        $this->completePaymentProcessing($orderTransaction, $transaction, $context, $queryParams, $verificationResult);
    }

    /**
     * Verifies a payment transaction with Paystack.
     *
     * @param OrderTransactionEntity $orderTransaction The Shopware order transaction entity.
     * @param array $queryParams Query parameters containing at least the 'transaction_id'.
     *
     * @return array The verification result from Paystack.
     *
     * @throws PaystackException If the transaction ID is missing or verification fails.
     */
    private function verifyPayment(OrderTransactionEntity $orderTransaction, array $queryParams): array
    {
        // 1. Check for the 'reference' parameter in the query parameters.
        $reference = $queryParams['reference'] ?? null;

        if (!$reference) {
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->error('Missing reference parameter in payment verification callback', [
                    'transaction_id' => $orderTransaction->getId(),
                    'query_params' => $queryParams,
                ]);
            }
            throw PaymentException::invalidTransaction($orderTransaction->getId());
        }

        // 2. Call the Paystack verification service.
        $verificationResult = $this->transactionService->verify($reference);

        // 3. Validate the verification response status.
        if (($verificationResult['status'] ?? '') !== true) {
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->error('Payment verification failed', [
                    'transaction_id' => $orderTransaction->getId(),
                    'verification_result' => $verificationResult,
                ]);
            }
            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransaction->getId(),
                $verificationResult['message'] ?? 'Payment verification failed'
            );
        }

        // Log success if debugging is enabled.
        if ($this->config->getBool('enableDebugging', null)) {
            $this->logger->info('Payment verification successful', [
                'transaction_id' => $orderTransaction->getId(),
                'verification_result' => $verificationResult,
            ]);
        }

        return $verificationResult;
    }

    /**
     * Completes the payment processing by updating transaction state and custom fields.
     *
     * @param OrderTransactionEntity $orderTransaction The order transaction entity.
     * @param PaymentTransactionStruct $transaction Payment transaction data.
     * @param Context $context Shopware context.
     * @param array $queryParams Query parameters from the payment gateway.
     * @param array $verificationResult Result from payment verification.
     */
    protected function completePaymentProcessing(
        OrderTransactionEntity $orderTransaction,
        PaymentTransactionStruct $transaction,
        Context $context,
        array $queryParams,
        array $verificationResult
    ): void {
        // Mark the transaction as paid in Shopware.
        $this->transactionStateHandler->paid($transaction->getOrderTransactionId(), $context);

        // Update the order transaction with custom fields built from the payment gateway response and verification result.
        $this->orderTransactionRepository->update([[
            'id' => $transaction->getOrderTransactionId(),
            'customFields' => [
                self::FIELD_REFERENCE       => $queryParams['reference'] ?? null,
                self::FIELD_TRANSACTION_ID  => $verificationResult['data']['id'] ?? null,
                self::FIELD_PAYMENT_TYPE    => $verificationResult['data']['channel'] ?? null,
                self::FIELD_TRANSACTION_FEE => isset($verificationResult['data']['fees']) ? ($verificationResult['data']['fees'] / 100) : null,
                self::FIELD_AMOUNT          => isset($verificationResult['data']['amount']) ? ($verificationResult['data']['amount'] / 100) : null,
                self::FIELD_CURRENCY        => $verificationResult['data']['currency'] ?? null,
                self::FIELD_VERIFIED_AT     => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
        ]], $context);

        // If debugging is enabled in the plugin configuration, log payment finalization details for troubleshooting.
        if ($this->config->getBool('enableDebugging', $orderTransaction->getOrder()?->getSalesChannelId())) {
            $this->logger->info('Payment finalized', [
                'transaction_id' => $transaction->getOrderTransactionId(),
                'paystack_reference' => $verificationResult['data']['id'] ?? null,
            ]);
        }
    }

    /**
     * @param string $transactionId
     * @param Context $context
     *
     * @return OrderTransactionEntity
     */
    public function getOrderTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = $this->getCriteria([$transactionId]);
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction instanceof OrderTransactionEntity) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'Order transaction with id ' . $transactionId . ' not found'
            );
        }

        if ($this->config->getBool('enableDebugging', $orderTransaction->getOrder()?->getSalesChannelId())) {
            $this->logger->info('Order transaction found', [
                'transaction_id' => $transactionId,
            ]);
        }

        return $orderTransaction;
    }

    private function getCriteria(array $ids = []): Criteria
    {
        $criteria = empty($ids) ? new Criteria() : new Criteria($ids);
        $criteria->addAssociations(['order.currency', 'order.orderCustomer.salutation']);

        return $criteria;
    }
}
