<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Payum\Action;

use Doctrine\ORM\EntityManagerInterface;
use MonextSyliusPlugin\Api\Client;
use MonextSyliusPlugin\Entity\MonextReference;
use MonextSyliusPlugin\Payum\MonextApi;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Response;

final class CaptureAction implements ActionInterface, ApiAwareInterface
{
    // @phpstan-ignore property.onlyWritten
    private ?MonextApi $api;

    public function __construct(
        private Client $client,
        private MonextReferenceRepository $monextRefRepo,
        private EntityManagerInterface $em
    ) {
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var SyliusPaymentInterface $payment */
        $payment = $request->getModel();

        try {
            $order = $payment->getOrder();

            if (!$order instanceof OrderInterface) {
                throw new \Exception('Order not found');
            }

            $response = $this->client->createSession($order, $payment);
            $monextReference = $this->monextRefRepo->findOneByPaymentId($payment->getId());

            if (!$monextReference instanceof MonextReference) {
                $monextReference = new MonextReference();
                $this->em->persist($monextReference);
                $monextReference->setPayment($payment);
            }

            if (isset($response['sessionId'])) {
                $monextReference->setToken($response['sessionId']);
            }

            $payment->setDetails($response);

            if (Response::HTTP_CREATED === $response['status']) {
                $request->getToken()->setAfterUrl($response['redirectURL']);
            }

            $this->em->flush();
        } catch (\Exception $e) {
            $payment->setDetails(['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'error' => $e->getMessage()]);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture
            && $request->getModel() instanceof SyliusPaymentInterface
        ;
    }

    public function setApi($api): void
    {
        if (!$api instanceof MonextApi) {
            throw new UnsupportedApiException('Not supported. Expected an instance of '.MonextApi::class);
        }

        $this->api = $api;
    }
}
