<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Helpers;

class ApiHelper
{
    public const REFUND_TYPE = 'REFUND';
    public const CANCEL_TYPE = 'CANCEL';
    public const CAPTURE_TYPE = 'CAPTURE';

    /**
     * @param array<string, mixed> $transactionDetails
     *
     * @throws \Exception
     */
    public function isTransactionAlreadyProcessed(
        string $transactionId,
        array $transactionDetails,
        string $transactionType
    ): bool {
        if (!isset(
            $transactionDetails['transaction']['paymentType'],
            $transactionDetails['transaction']['capture'],
            $transactionDetails['transaction']['requestedAmount']
        )) {
            throw new \Exception('Missing transaction details from Monext for transaction '.$transactionId);
        }

        if (!isset($transactionDetails['associatedTransactions'])) {
            return false;
        }

        $capturedAmount = 0;
        $epsilon = 0.0001;

        foreach ($transactionDetails['associatedTransactions'] as $transaction) {
            if ($transactionType === $transaction['type'] && 'OK' === $transaction['status']) {
                $capturedAmount += $transaction['amount'];
            }
        }

        if (abs($capturedAmount - $transactionDetails['transaction']['requestedAmount']) < $epsilon) {
            return true;
        }

        return false;
    }
}
