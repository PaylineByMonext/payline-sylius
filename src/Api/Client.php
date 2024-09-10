<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Api;

use Doctrine\Common\Collections\Collection;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use MonextSyliusPlugin\Helpers\ConfigHelper;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Symfony\Component\HttpFoundation\Response;

class Client
{
    public const SESSIONS_ENDPOINT = '/checkout/sessions';
    public const TRANSACTIONS_ENDPOINT = '/checkout/transactions';

    public function __construct(
        protected GuzzleClient $httpClient,
        protected ConfigHelper $configHelper,
        protected MonextReferenceRepository $monextRefRepo,
        protected TaxRateResolverInterface $taxRateResolver
    ) {
    }

    /**
     * Wrapper for API calls to handle exceptions and fetch config.
     *
     * @param array<int|string,mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function call(string $method, string $url, PaymentInterface $payment, array $body = []): array
    {
        try {
            $gatewayConfig = $this->configHelper->getGatewayConfig($payment);
        } catch (\Exception $e) {
            return ['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'error' => $e->getMessage()];
        }

        $options = [
            'headers' => [
                'authorization' => 'Basic '.$gatewayConfig[ConfigHelper::FIELD_API_KEY],
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
            'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
        ];

        try {
            $response = $this->httpClient->request(
                $method,
                rtrim($gatewayConfig[ConfigHelper::FIELD_ENVIRONMENT], '/ ').$url,
                $options
            );
        } catch (ClientException|ServerException $e) {
            return [
                'status' => $e->getResponse()->getStatusCode(),
                'error' => json_decode($e->getResponse()->getBody()->getContents(), true),
            ];
        } catch (\Exception|GuzzleException $e) {
            return [
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'error' => [$e->getMessage()],
            ];
        }

        return array_merge(
            ['status' => $response->getStatusCode()],
            json_decode($response->getBody()->getContents(), true)
        );
    }

    /**
     * @param Collection<int, OrderItemInterface> $orderItems
     *
     * @return array<int, array<string, int|float|string>>
     */
    protected function prepareOrderItemsDetails(Collection $orderItems): array
    {
        $orderItemsFormatted = [];

        foreach ($orderItems as $item) {
            $rate = $this->taxRateResolver->resolve($item->getVariant());

            $taxons = $item->getProduct()->getTaxons()->toArray();
            $subCategoryOne = array_pop($taxons);
            $subCategoryTwo = array_pop($taxons);

            $orderItemsFormatted[] = [
                'reference' => $item->getVariant()->getCode(),
                'taxRate' => null !== $rate ? floor($rate->getAmountAsPercentage() * 100) : 0,
                'subCategory1' => (string) $subCategoryOne?->getName(),
                'subCategory2' => (string) $subCategoryTwo?->getName(),
                'price' => $item->getTotal(),
                'quantity' => $item->getQuantity(),
            ];
        }

        return $orderItemsFormatted;
    }

    /**
     * Prepare the payload data for the createSession API call.
     *
     * @param array<string, string> $gatewayConfig
     *
     * @return array<string, mixed>
     */
    protected function prepareCreateSessionPayload(OrderInterface $order, array $gatewayConfig): array
    {
        $splitLocaleCode = explode('_', $order->getLocaleCode());

        return [
            'pointOfSaleReference' => $gatewayConfig[ConfigHelper::FIELD_POINT_OF_SALE],
            'paymentMethod' => [
                'paymentMethodIDs' => explode(',', $gatewayConfig[ConfigHelper::FIELD_CONTRACTS_NUMBER]),
            ],
            'payment' => [
                'paymentType' => 'ONE_OFF',
                'capture' => $gatewayConfig[ConfigHelper::FIELD_CAPTURE_TYPE],
            ],
            'order' => [
                'currency' => $order->getCurrencyCode(),
                'origin' => 'E_COM',
                'country' => $order->getShippingAddress()->getCountryCode(),
                'reference' => $order->getNumber(),
                'amount' => $order->getTotal(),
                'taxes' => $order->getTaxTotal(),
                'discount' => abs($order->getOrderPromotionTotal()),
                'items' => $this->prepareOrderItemsDetails($order->getItems()),
            ],
            'buyer' => [
                'legalStatus' => 'PRIVATE',
                'id' => $order->getCustomer()->getId(),
                'firstName' => $order->getCustomer()->getFirstName(),
                'lastName' => $order->getCustomer()->getLastName(),
                'email' => $order->getCustomer()->getEmail(),
                'mobile' => $order->getCustomer()->getPhoneNumber(),
                'birthDate' => $order->getCustomer()->getBirthday()?->format('Y-m-d'),
                'billingAddress' => [
                    'country' => $order->getBillingAddress()->getCountryCode(),
                    'firstName' => $order->getBillingAddress()->getFirstName(),
                    'lastName' => $order->getBillingAddress()->getLastName(),
                    'email' => $order->getCustomer()->getEmail(),
                    'mobile' => $order->getBillingAddress()->getPhoneNumber(),
                    'street' => $order->getBillingAddress()->getStreet(),
                    'city' => $order->getBillingAddress()->getCity(),
                    'zip' => $order->getBillingAddress()->getPostcode(),
                    'addressCreateDate' => $order
                        ->getBillingAddress()
                        ->getCreatedAt()
                        ->format(\DateTimeInterface::ATOM),
                ],
            ],
            'delivery' => [
                'charge' => $order->getShippingTotal(),
                'provider' => $order->getShipments()->last()->getMethod()->getName(),
                'address' => [
                    'country' => $order->getShippingAddress()->getCountryCode(),
                    'firstName' => $order->getShippingAddress()->getFirstName(),
                    'lastName' => $order->getShippingAddress()->getLastName(),
                    'email' => $order->getCustomer()->getEmail(),
                    'mobile' => $order->getShippingAddress()->getPhoneNumber(),
                    'street' => $order->getShippingAddress()->getStreet(),
                    'city' => $order->getShippingAddress()->getCity(),
                    'zip' => $order->getShippingAddress()->getPostcode(),
                    'addressCreateDate' => $order
                        ->getShippingAddress()
                        ->getCreatedAt()
                        ->format(\DateTimeInterface::ATOM),
                ],
            ],
            'threeDS' => ['challengeInd' => 'NO_PREFERENCE'],
            'returnURL' => $this->configHelper->getUrl('monext_shop_return', $order->getChannel()),
            'notificationURL' => $this->configHelper->getUrl('monext_shop_notification', $order->getChannel()),
            'languageCode' => strtoupper($splitLocaleCode[0]),
        ];
    }

    /**
     * Calls Monext endpoint to create a payment session.
     * See: https://api-docs.retail.monext.com/reference/sessioncreate.
     *
     * @return array<string, mixed>
     */
    public function createSession(OrderInterface $order, PaymentInterface $payment): array
    {
        try {
            $gatewayConfig = $this->configHelper->getGatewayConfig($payment);
        } catch (\Exception $e) {
            return ['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'error' => $e->getMessage()];
        }

        return $this->call(
            'POST',
            self::SESSIONS_ENDPOINT,
            $payment,
            $this->prepareCreateSessionPayload($order, $gatewayConfig)
        );
    }

    /**
     * Calls Monext Endpoint to capture a transaction.
     * See: https://api-docs.retail.monext.com/reference/sessionget.
     *
     * @return array<string, mixed>
     */
    public function getSessionDetails(string $sessionId, PaymentInterface $payment): array
    {
        return $this->call(
            'GET',
            self::SESSIONS_ENDPOINT.'/'.$sessionId,
            $payment
        );
    }

    /**
     * Calls Monext Endpoint to get transaction details.
     * See: https://api-docs.retail.monext.com/reference/transactionget-1.
     *
     * @return array<string, mixed>
     */
    public function getTransactionDetail(string $transactionId, PaymentInterface $payment): array
    {
        return $this->call(
            'GET',
            self::TRANSACTIONS_ENDPOINT.'/'.$transactionId,
            $payment
        );
    }

    /**
     * Calls Monext Endpoint to capture a transaction.
     * See: https://api-docs.retail.monext.com/reference/transactioncapture.
     *
     * @return array<string, mixed>
     */
    public function captureTransaction(string $transactionId, PaymentInterface $payment): array
    {
        return $this->call(
            'POST',
            self::TRANSACTIONS_ENDPOINT.'/'.$transactionId.'/captures',
            $payment,
            ['amount' => $payment->getAmount()]
        );
    }

    /**
     * Calls Monext endpoint to cancel a transaction.
     * See: https://api-docs.retail.monext.com/reference/transactionreset.
     *
     * @return array<string, mixed>
     */
    public function cancelTransaction(string $transactionId, PaymentInterface $payment): array
    {
        return $this->call(
            'POST',
            self::TRANSACTIONS_ENDPOINT.'/'.$transactionId.'/cancels',
            $payment,
            ['amount' => $payment->getAmount()]
        );
    }

    /**
     * Calls Monext endpoint to refund a transaction.
     * See: https://api-docs.retail.monext.com/reference/transactionrefund.
     *
     * @return array<string, mixed>
     */
    public function refundTransaction(string $transactionId, PaymentInterface $payment): array
    {
        return $this->call(
            'POST',
            self::TRANSACTIONS_ENDPOINT.'/'.$transactionId.'/refunds',
            $payment,
            ['amount' => $payment->getAmount()]
        );
    }
}
