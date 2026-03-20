<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Payment\PaymentException;

class OrderTransactionService
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    /**
     * @param string $transactionId
     * @param Context $context
     *
     * @return OrderTransactionEntity
     */
    public function get(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = $this->getCriteria([$transactionId]);
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction instanceof OrderTransactionEntity) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                sprintf('Order transaction "%s" could not be found.', $transactionId)
            );
        }

        return $orderTransaction;
    }

    /**
     * @param string $transactionId
     * @param array $customFields
     * @param Context $context
     */
    public function updateCustomFields(string $transactionId, array $customFields, Context $context): void
    {
        $this->orderTransactionRepository->update([[
            'id' => $transactionId,
            'customFields' => $customFields,
        ]], $context);
    }

    private function getCriteria(array $ids = []): Criteria
    {
        $criteria = empty($ids) ? new Criteria() : new Criteria($ids);
        $criteria->addAssociations(['order.currency', 'order.orderCustomer.salutation']);

        return $criteria;
    }
}
