<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MonextSyliusPlugin\Entity\MonextReference;
use MonextSyliusPlugin\Helpers\ConfigHelper;
use Psr\Log\LoggerInterface;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

class SaveSessionDetailsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private StateMachineFactoryInterface $stateMachineFactory,
        private ConfigHelper $configHelper,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Ensures API response is in a correct format.
     * See response formats from: https://api-docs.retail.monext.com/reference/sessionget.
     *
     * @param array<string, mixed> $response
     */
    protected function validateResponse(array $response): bool
    {
        if (!isset($response['status'])) {
            return false;
        }

        if (Response::HTTP_OK !== $response['status']) {
            return isset($response['error']['title']) && isset($response['error']['detail']);
        }

        if (!isset($response['result']['title']) || !isset($response['result']['detail'])) {
            return false;
        }

        if (!isset($response['transactions']) || !isset($response['transactions'][0]['capture'])) {
            return false;
        }

        return true;
    }

    /**
     * Takes a response from getSessionDetails API call, save data and returns result in the same format.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $response, MonextReference $reference): array
    {
        if (!$this->validateResponse($response)) {
            $this->logger->debug(sprintf('[MONEXT] Invalid response format for token %s: %s', $reference->getToken(), json_encode($response)));

            return [
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'error' => [
                    'title' => 'ERROR',
                    'detail' => 'Invalid response format.',
                ],
            ];
        }

        if (Response::HTTP_OK !== $response['status']) {
            return $response;
        }

        // Stores transaction info.
        $firstTransaction = false;

        if (isset($response['transactions']) && is_array($response['transactions'])) {
            $firstTransaction = reset($response['transactions']);
        }

        if (false === $firstTransaction || !is_array($firstTransaction)) {
            return [
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'error' => [
                    'title' => 'ERROR',
                    'detail' => 'No transaction found in response.',
                ],
            ];
        }

        $reference->setTransactionId($firstTransaction['id']);
        $this->em->flush();

        // Check and update payment with new state if we can.
        $newState = $this->configHelper->convertPaymentState($response['result']['title'], $firstTransaction['capture']);
        $transition = $this->configHelper->convertPaymentTransition($newState);

        try {
            $stateMachine = $this->stateMachineFactory->get($reference->getPayment(), 'sylius_payment');

            if ('' === $transition || !$stateMachine->can($transition)) {
                return [
                    'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'error' => [
                        'title' => 'ERROR',
                        'detail' => 'State mismatch, cannot apply given state to target.',
                    ],
                ];
            }

            $stateMachine->apply($transition);
            $this->em->flush();
        } catch (\Exception $e) {
            return [
                'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'error' => [
                    'title' => 'ERROR',
                    'detail' => $e->getMessage(),
                ],
            ];
        }

        if (PaymentInterface::STATE_FAILED === $newState) {
            return [
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'error' => [
                    'title' => 'ERROR',
                    'detail' => 'Payment failed.',
                ],
            ];
        }

        return [
            'status' => Response::HTTP_OK,
            'result' => [
                'title' => 'ACCEPTED',
                'detail' => 'OK',
            ],
        ];
    }
}
