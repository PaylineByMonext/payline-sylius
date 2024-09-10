<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

final class StatusAction implements ActionInterface
{
    public function execute(mixed $request): void
    {
        /* @var GetStatus $request */
        RequestNotSupportedException::assertSupports($this, $request);
        /** @var PaymentInterface $payment */
        $payment = $request->getFirstModel();
        $details = $payment->getDetails();

        if (!isset($details['status']) || Response::HTTP_CREATED !== $details['status']) {
            $request->markFailed();
        }
    }

    public function supports(mixed $request): bool
    {
        return
            $request instanceof GetStatusInterface
            // @phpstan-ignore method.notFound
            && $request->getFirstModel() instanceof PaymentInterface
        ;
    }
}
