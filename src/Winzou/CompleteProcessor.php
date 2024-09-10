<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Winzou;

use Doctrine\ORM\EntityManagerInterface;
use MonextSyliusPlugin\Api\Client;
use MonextSyliusPlugin\Entity\MonextReference;
use MonextSyliusPlugin\Helpers\ApiHelper;
use MonextSyliusPlugin\Payum\MonextGatewayFactory;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Psr\Log\LoggerInterface;
use SM\Event\TransitionEvent;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use SM\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

class CompleteProcessor
{
    private PaymentInterface $payment;
    private StateMachineInterface $stateMachine;

    public function __construct(
        private Client $client,
        private MonextReferenceRepository $monextRefRepo,
        private StateMachineFactoryInterface $stateMachineFactory,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ApiHelper $apiHelper
    ) {
    }

    /**
     * Hook on "complete" transition for "sylius_payment" to trigger capture if it should do it.
     *
     * @throws \Exception
     */
    public function complete(PaymentInterface $payment, TransitionEvent $event): void
    {
        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();

        // Only process Monext payments.
        if (MonextGatewayFactory::FACTORY_NAME !== $method->getGatewayConfig()->getFactoryName()) {
            return;
        }

        $this->payment = $payment;
        $this->stateMachine = $this->stateMachineFactory->get($this->payment, 'sylius_payment');

        // Fetching and validating data.
        try {
            $reference = $this->monextRefRepo->findOneByPaymentId($this->payment->getId());

            if (!$reference instanceof MonextReference) {
                $this->failChange('Reference not found for payment '.$this->payment->getId());
            }

            $transactionId = $reference->getTransactionId();

            if (null === $transactionId || '' === $transactionId) {
                $this->logger->notice(
                    sprintf(
                        '[MONEXT] Complete payment %s (token: %s) without a transaction ID.',
                        $this->payment->getId(),
                        $reference->getToken()
                    )
                );

                return;
            }

            // Fetch info from Monext to validate we need to capture this transaction.
            $transactionDetails = $this->client->getTransactionDetail($transactionId, $this->payment);

            // Skip capture if the whole amount is already captured.
            if ($this->apiHelper->isTransactionAlreadyProcessed(
                $transactionId,
                $transactionDetails,
                ApiHelper::CAPTURE_TYPE
            )) {
                return;
            }

            // If transaction is not meant to be manually captured, skip it too.
            if ('ONE_OFF' !== $transactionDetails['transaction']['paymentType']
                || 'MANUAL' !== $transactionDetails['transaction']['capture']
            ) {
                return;
            }
        } catch (\Exception $e) {
            $this->failChange($e->getMessage());
        }

        // Call Monext API to capture transaction.
        try {
            // @phpstan-ignore variable.undefined, variable.undefined
            $response = $this->client->captureTransaction($reference->getTransactionId(), $reference->getPayment());

            if (Response::HTTP_CREATED !== $response['status'] && Response::HTTP_ACCEPTED !== $response['status']) {
                $this->payment->setDetails(['error' => $response['error']]);
            } elseif (!$this->stateMachine->can('complete')) {
                $this->payment->setDetails(['error' => 'Payment workflow does not authorized "pay" transition']);
            } elseif ('ERROR' === $response['result']['title']) { // This API returns payment errors as 201s.
                $this->payment->setDetails(['error' => $response['result']]);
            }

            $this->em->flush();
        } catch (\Exception $e) {
            $this->failChange($e->getMessage());
        }
    }

    /**
     * @throws \Exception
     */
    protected function failChange(
        string $message
    ): void {
        $this->payment->setDetails(['error' => $message]);
        $this->logger->error('[MONEXT] '.$message);

        try {
            if (in_array('fail', $this->stateMachine->getPossibleTransitions(), true)) {
                $this->stateMachine->apply('fail');
            }
        } catch (\Exception $e) {
            $this->logger->error('[MONEXT] '.$e->getMessage());
            $this->payment->setDetails(['error' => $message.' / '.$e->getMessage()]);
        }

        $this->em->flush();
        throw new \Exception($message);
    }
}
