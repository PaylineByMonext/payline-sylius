<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Controller\Shop;

use Doctrine\ORM\NonUniqueResultException;
use MonextSyliusPlugin\Api\Client;
use MonextSyliusPlugin\Entity\MonextReference;
use MonextSyliusPlugin\Handler\SaveSessionDetailsHandler;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class NotificationAction
{
    public function __construct(
        private Client $client,
        private MonextReferenceRepository $monextRefRepo,
        private SaveSessionDetailsHandler $saveSessionDetailsHandler,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handles notification from PSP.
     * See: https://docs.monext.fr/display/DT/Notification+par+URL.
     */
    public function __invoke(Request $request): Response
    {
        $token = $request->query->getString('token');
        $notificationType = $request->query->getString('notificationType');

        // Parameters checks.
        if ('WEBTRS' !== $notificationType) {
            $this->logger->notice(
                sprintf(
                    '[MONEXT] Method not supported "%s" for query %s',
                    $notificationType,
                    $request->getUri()
                )
            );

            return new Response('Method not allowed', Response::HTTP_NOT_IMPLEMENTED);
        }

        if ('' === $token) {
            $this->logger->notice(sprintf('[MONEXT] Missing required parameter token for query %s', $request->getUri()));

            return new Response('Missing required parameter', Response::HTTP_BAD_REQUEST);
        }

        // Entity fetching and getting data from Monext.
        try {
            $reference = $this->monextRefRepo->findOneByToken($token);

            if (!$reference instanceof MonextReference) {
                $this->logger->notice('[MONEXT] Monext reference not found for token '.$token);

                return new Response('Reference not found for token '.$token, Response::HTTP_NOT_FOUND);
            }

            $response = $this->client->getSessionDetails($token, $reference->getPayment());
        } catch (NonUniqueResultException $e) {
            $this->logger->error('[MONEXT] Multiple references found for token '.$token);

            return new Response('Multiple references found for token '.$token, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error('[MONEXT] Error while fetching data for token '.$token.' - '.$e->getMessage());

            return new Response('Error: '.$e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $processedResponse = ($this->saveSessionDetailsHandler)($response, $reference);

        if (Response::HTTP_OK !== $processedResponse['status']) {
            $this->logger->error(
                sprintf(
                    '[MONEXT] Error while processing response for token %s - %s',
                    $token,
                    json_encode($processedResponse)
                )
            );

            return new Response('Error: '.$processedResponse['error']['detail'], $processedResponse['status']);
        }

        if (!isset($processedResponse['result']['title'])) {
            $this->logger->error(sprintf('[MONEXT] Invalid response format for token %s - %s', $token, json_encode($processedResponse)));

            return new Response('Error: Invalid response format.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('OK', Response::HTTP_OK);
    }
}
