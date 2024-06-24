<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Winzou;

use MonextSyliusPlugin\Helpers\ConfigHelper;
use MonextSyliusPlugin\Payum\MonextGatewayFactory;
use Psr\Log\LoggerInterface;
use SM\Event\TransitionEvent;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use SM\SMException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

class ShippingProcessor
{
    public function __construct(
        private StateMachineFactoryInterface $stateMachineFactory,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Hook on all "after" transitions for "sylius_order_shipping" state machine.
     * Used for Monext special cases like capture triggered at shipping.
     *
     * @throws \Exception
     */
    public function capture(OrderInterface $order, TransitionEvent $event): void
    {
        $payment = $order->getLastPayment();

        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();
        $config = $method->getGatewayConfig()->getConfig();

        // Only process Monext payments.
        if (MonextGatewayFactory::FACTORY_NAME !== $method->getGatewayConfig()->getFactoryName()
            || !isset($config[ConfigHelper::FIELD_MANUAL_CAPTURE_TRANSITION])
        ) {
            return;
        }

        $transitionsWatched = explode(',', $config[ConfigHelper::FIELD_MANUAL_CAPTURE_TRANSITION]);

        // Not our use-case, skip.
        if (!in_array($event->getTransition(), $transitionsWatched, true)) {
            return;
        }

        $paymentStateMachine = $this->stateMachineFactory->get($payment, 'sylius_payment');

        try {
            // Transaction capture is handled in "complete" payment transition already.
            $paymentStateMachine->apply('complete');
        } catch (SMException $e) {
            // Payment can't be completed, continue shipping process anyway.
            $this->logger->notice(
                sprintf(
                    '[MONEXT] Skip capture because payment %s can not be completed (current state: %s)',
                    $payment->getId(),
                    $paymentStateMachine->getState()
                )
            );
        }
    }
}
