<?php

declare(strict_types=1);

namespace Tests\MonextSyliusPlugin\Integration\Service;

use GuzzleHttp\Client;
use MonextSyliusPlugin\Payum\Action\CaptureAction;
use MonextSyliusPlugin\Payum\MonextApi;
use Payum\Core\Request\Capture;
use Psr\Http\Message\ResponseInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\Payment;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CaptureActionTest extends KernelTestCase
{
    private ?ContainerInterface $container;

    public function setUp(): void
    {
        self::bootKernel();
        $this->container = static::getContainer();
    }

    public function testCaptureAction(): void
    {
        $payment = new Payment();

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse
            ->expects(self::any())
            ->method('getStatusCode')
            ->willReturn(200)
        ;

        $mockClient = $this->createMock(Client::class);
        $mockClient
            ->expects(self::any())
            ->method('request')
            ->willReturn($mockResponse)
        ;

        $this->container->set(Client::class, $mockClient);

        $payment->setCurrencyCode('EUR');
        $payment->setAmount(100);
        $monextApi = new MonextApi('FAKE');
        $request = new Capture($payment);

        /** @var CaptureAction $captureAction */
        $captureAction = $this->container->get(CaptureAction::class);
        $captureAction->setApi($monextApi);
        $captureAction->execute($request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        $this->assertSame($payment->getDetails()['status'], 200);
    }
}
