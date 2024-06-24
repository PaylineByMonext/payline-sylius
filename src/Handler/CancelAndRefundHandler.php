<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MonextSyliusPlugin\Api\Client;
use MonextSyliusPlugin\Entity\MonextReference;
use MonextSyliusPlugin\Helpers\ApiHelper;
use MonextSyliusPlugin\Payum\MonextGatewayFactory;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

class CancelAndRefundHandler
{
    private PaymentInterface $payment;
    private string $methodName;

    public function __construct(
        private Client $client,
        private MonextReferenceRepository $monextRefRepo,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ApiHelper $apiHelper
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(string $methodName, PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();

        // Not concerned, skip.
        if (MonextGatewayFactory::FACTORY_NAME !== $method->getGatewayConfig()->getFactoryName()) {
            return;
        }

        $this->methodName = $methodName;
        $this->payment = $payment;

        // Fetching and validating data.
        try {
            $reference = $this->monextRefRepo->findOneByPaymentId($this->payment->getId());

            if (!$reference instanceof MonextReference) {
                // Cannot refund without a reference.
                if (ApiHelper::REFUND_TYPE === $methodName) {
                    throw new \Exception('No Monext reference during refund found for payment '.$payment->getId());
                }

                // No ref and cancel, skip to cancel the unfinished order.
                return;
            }

            $transactionId = $reference->getTransactionId();

            if (null === $transactionId || '' === $transactionId) {
                if (ApiHelper::REFUND_TYPE === $this->methodName) {
                    throw new \Exception('Transaction ID not found');
                }

                $this->logger->notice(
                    sprintf(
                        '[MONEXT] Payment cancelled but transaction ID not found for payment %s (token: %s)',
                        $this->payment->getId(),
                        $reference->getToken()
                    )
                );

                return;
            }

            // Fetch info from Monext to validate we need to capture this transaction.
            $transactionDetails = $this->client->getTransactionDetail($transactionId, $this->payment);

            // Get the amount already cancelled/refunded and skip if we're already done.
            if ($this->apiHelper->isTransactionAlreadyProcessed(
                $transactionId,
                $transactionDetails,
                $methodName // TODO: RESET is the value for cancel here...
            )) {
                return;
            }
        } catch (\Exception $e) {
            $this->failChange($e->getMessage());
        }

        // Call Monext API and process result.
        try {
            if (ApiHelper::REFUND_TYPE === $this->methodName) {
                // @phpstan-ignore variable.undefined, variable.undefined
                $response = $this->client->refundTransaction($reference->getTransactionId(), $reference->getPayment());
            } elseif (ApiHelper::CANCEL_TYPE === $this->methodName) {
                // @phpstan-ignore variable.undefined, variable.undefined
                $response = $this->client->cancelTransaction($reference->getTransactionId(), $reference->getPayment());
            } else {
                throw new \Exception(sprintf('Invalid method name: "%s"', $this->methodName));
            }

            // This API returns error as 201s.
            if (Response::HTTP_CREATED !== $response['status'] && Response::HTTP_ACCEPTED !== $response['status']) {
                throw new \Exception($response['error']);
            }

            if ('ERROR' === $response['result']['title']) {
                throw new \Exception(json_encode($response['result']));
            }
        } catch (\Exception $e) {
            $this->failChange($e->getMessage());
        }

        //        throw new \Exception('nono');
    }

    /**
     * @throws \Exception
     */
    protected function failChange(string $message): void
    {
        $this->payment->setDetails(['error' => $message]);
        $this->logger->error(
            sprintf('[MONEXT] Error while processing "%s" payment %s', $this->methodName, $this->payment->getId())
        );
        $this->logger->error('[MONEXT] '.$message);
        $this->em->flush();
        throw new \Exception($message);
    }
}
