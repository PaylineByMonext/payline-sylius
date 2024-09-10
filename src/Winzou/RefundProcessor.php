<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Winzou;

use MonextSyliusPlugin\Handler\CancelAndRefundHandler;
use MonextSyliusPlugin\Helpers\ApiHelper;
use Sylius\Component\Payment\Model\PaymentInterface;

class RefundProcessor
{
    public function __construct(
        private CancelAndRefundHandler $cancelAndRefundHandler
    ) {
    }

    /**
     * @throws \Exception
     */
    public function refund(PaymentInterface $payment): void
    {
        ($this->cancelAndRefundHandler)(ApiHelper::REFUND_TYPE, $payment);
    }
}
