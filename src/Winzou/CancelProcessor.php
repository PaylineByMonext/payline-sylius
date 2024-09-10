<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Winzou;

use MonextSyliusPlugin\Handler\CancelAndRefundHandler;
use MonextSyliusPlugin\Helpers\ApiHelper;
use Sylius\Component\Payment\Model\PaymentInterface;

class CancelProcessor
{
    public function __construct(
        private CancelAndRefundHandler $cancelAndRefundHandler,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function cancel(PaymentInterface $payment): void
    {
        ($this->cancelAndRefundHandler)(ApiHelper::CANCEL_TYPE, $payment);
    }
}
