<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Checkout\Payment;

use Kommandhub\Paystack\Exceptions\PaystackException;
use Kommandhub\PaystackSW\Util\PaystackConstants;
use Kommandhub\PaystackSW\Service\Config;
use Kommandhub\PaystackSW\Service\OrderTransactionService;
use Kommandhub\PaystackSW\Service\PayloadBuilder;
use Kommandhub\PaystackSW\Service\TransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PaystackPaymentHandler is responsible for handling payment transactions with Paystack in a Shopware environment.
 *
 * This class implements the necessary methods to initialize payments, handle callbacks from Paystack, and update
 * the order transaction state accordingly. It also manages custom fields to store Paystack transaction details.
 */
class PaystackPaymentHandler extends AbstractPaystackPaymentHandler
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
        private readonly TransactionService $transactionService,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Initializes the payment process by preparing the payload and redirecting to Paystack's checkout page.
     *
     * @param Request $request The HTTP request object containing request data.
     * @param PaymentTransactionStruct $transaction The payment transaction struct from Shopware.
     * @param Context $context The Shopware context for the operation.
     * @param Struct|null $validateStruct Optional struct for additional validation (not used in this implementation).
     *
     * @return RedirectResponse|null A redirect response to Paystack's checkout page or null if initialization fails.
     *
     * @throws PaymentException If there is an error during payment initialization or communication with Paystack.
     */
    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        // 1. Retrieve the full order transaction entity including associated order and customer data.
        $orderTransaction = $this->orderTransactionService->get($transaction->getOrderTransactionId(), $context);

        try {
            // 2. Prepare the payment payload for Paystack initialization.
            $payload = $this->payloadBuilder->build($orderTransaction, $transaction);
        } catch (\RuntimeException $e) {
            // Handle cases where required order data might be missing.
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->error('Failed to build payment payload for Paystack.', [
                    'transaction_id' => $transaction->getOrderTransactionId(),
                    'error' => $e->getMessage(),
                ]);
            }

            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'Unable to prepare payment data: ' . $e->getMessage()
            );
        }

        try {
            // 3. Initialize the transaction with Paystack API.
            $response = $this->transactionService->initialize($payload);

            // 4. Validate the response status. Status 'true' indicates successful initialization.
            if (($response['status'] ?? '') !== true) {
                throw PaymentException::asyncProcessInterrupted(
                    $transaction->getOrderTransactionId(),
                    'Paystack declined to initialize the payment: ' . ($response['message'] ?? 'No reason provided')
                );
            }

            // Log initialization success if debugging is enabled.
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->info('Paystack payment session initialized successfully.', [
                    'transaction_id' => $transaction->getOrderTransactionId(),
                    'paystack_response' => $response,
                ]);
            }

            // 5. Redirect the customer to Paystack's hosted checkout page.
            if (!isset($response['data']['authorization_url'])) {
                throw PaymentException::asyncProcessInterrupted(
                    $transaction->getOrderTransactionId(),
                    'Paystack did not return a checkout URL. Please try again or contact support.'
                );
            }

            return new RedirectResponse($response['data']['authorization_url']);
        } catch (PaystackException $e) {
            // Handle network errors or API-specific failures.
            // @codeCoverageIgnoreStart
            if ($this->config->getBool('enableDebugging', null)) {
                $this->logger->error('Communication error with Paystack during payment initialization.', [
                    'transaction_id' => $transaction->getOrderTransactionId(),
                    'error' => $e->getMessage(),
                ]);
            }
            // @codeCoverageIgnoreEnd
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'A communication error occurred with the payment gateway. Please try again.' . PHP_EOL . $e->getMessage()
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
        $orderTransaction = $this->orderTransactionService->get($transaction->getOrderTransactionId(), $context);

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
                $this->logger->error('Payment callback received without a transaction reference.', [
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
                $this->logger->error('Paystack payment verification failed.', [
                    'transaction_id' => $orderTransaction->getId(),
                    'verification_result' => $verificationResult,
                ]);
            }
            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransaction->getId(),
                'Payment could not be verified: ' . ($verificationResult['message'] ?? 'No reason provided')
            );
        }

        // Log success if debugging is enabled.
        if ($this->config->getBool('enableDebugging', null)) {
            $this->logger->info('Paystack payment verified successfully.', [
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
        $this->orderTransactionService->updateCustomFields($transaction->getOrderTransactionId(), [
            PaystackConstants::FIELD_REFERENCE       => $queryParams['reference'] ?? null,
            PaystackConstants::FIELD_TRANSACTION_ID  => $verificationResult['data']['id'] ?? null,
            PaystackConstants::FIELD_PAYMENT_TYPE    => $verificationResult['data']['channel'] ?? null,
            PaystackConstants::FIELD_TRANSACTION_FEE => isset($verificationResult['data']['fees']) ? ($verificationResult['data']['fees'] / 100) : null,
            PaystackConstants::FIELD_AMOUNT          => isset($verificationResult['data']['amount']) ? ($verificationResult['data']['amount'] / 100) : null,
            PaystackConstants::FIELD_CURRENCY        => $verificationResult['data']['currency'] ?? null,
            PaystackConstants::FIELD_VERIFIED_AT     => (new \DateTime())->format('Y-m-d H:i:s'),
        ], $context);

        // If debugging is enabled in the plugin configuration, log payment finalization details for troubleshooting.
        if ($this->config->getBool('enableDebugging', $orderTransaction->getOrder()?->getSalesChannelId())) {
            $this->logger->info('Payment finalized and order transaction updated.', [
                'transaction_id' => $transaction->getOrderTransactionId(),
                'paystack_transaction_id' => $verificationResult['data']['id'] ?? null,
                'paystack_reference' => $queryParams['reference'] ?? null,
            ]);
        }
    }

    /**
     * @param string $transactionId
     * @param Context $context
     *
     * @return OrderTransactionEntity
     *
     * @deprecated Use OrderTransactionService::get() instead
     */
    public function getOrderTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $orderTransaction = $this->orderTransactionService->get($transactionId, $context);

        if ($this->config->getBool('enableDebugging', $orderTransaction->getOrder()?->getSalesChannelId())) {
            $this->logger->info('Order transaction loaded successfully.', [
                'transaction_id' => $transactionId,
            ]);
        }

        return $orderTransaction;
    }
}
