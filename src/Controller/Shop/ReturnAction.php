<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Controller\Shop;

use Doctrine\ORM\NonUniqueResultException;
use MonextSyliusPlugin\Api\Client;
use MonextSyliusPlugin\Entity\MonextReference;
use MonextSyliusPlugin\Handler\SaveSessionDetailsHandler;
use MonextSyliusPlugin\Helpers\ConfigHelper;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class ReturnAction extends AbstractController
{
    public function __construct(
        private Client $client,
        private MonextReferenceRepository $monextRefRepo,
        private ConfigHelper $configHelper,
        private SaveSessionDetailsHandler $saveSessionDetailsHandler,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handles return from PSP and redirect to homepage with error or checkout success.
     * See: https://docs.monext.fr/pages/viewpage.action?pageId=747146802.
     */
    public function __invoke(Request $request): Response
    {
        $token = $request->query->getString('paylinetoken');

        // No token, don't even bother with flash message, it's not a call from Monext.
        if ('' === $token) {
            return $this->redirectToRoute('sylius_shop_homepage', [], Response::HTTP_SEE_OTHER);
        }

        // Entity fetching and getting data from Monext.
        try {
            $reference = $this->monextRefRepo->findOneByToken($token);

            if (!$reference instanceof MonextReference) {
                $this->logger->notice('[MONEXT] Monext reference not found for token '.$token);
                $this->addFlash('info', 'monext.return.not_found');

                return $this->redirectToRoute('sylius_shop_homepage', [], Response::HTTP_SEE_OTHER);
            }

            $response = $this->client->getSessionDetails($token, $reference->getPayment());
        } catch (NonUniqueResultException $e) {
            $this->logger->error('[MONEXT] Duplicate reference found for token '.$token);
            $this->addFlash('error', 'monext.return.duplicate');

            return $this->redirectToRoute('sylius_shop_homepage', [], Response::HTTP_SEE_OTHER);
        }

        $processedResponse = ($this->saveSessionDetailsHandler)($response, $reference);

        // Redirecting to correct page with flash message if needed.
        if (Response::HTTP_OK !== $processedResponse['status']) {
            $this->logger->error(
                sprintf('[MONEXT] getSessionDetails error for token %s: %s', $token, json_encode($processedResponse))
            );
            if (!isset($processedResponse['error']['title'])) {
                $this->addFlash('error', 'monext.return.error');

                return $this->redirectToRoute('sylius_shop_homepage', [], Response::HTTP_SEE_OTHER);
            }

            $payload = $processedResponse['error'];
            $flashMessage = $this->configHelper->getFlashFromReturnCode($payload['title'] ?? 'unknown');
            $this->addFlash($flashMessage[0], $flashMessage[1]);

            return $this->redirectToRoute('sylius_shop_homepage', [], Response::HTTP_SEE_OTHER);
        }

        if (isset($processedResponse['result']['title']) && 'ACCEPTED' !== $processedResponse['result']['title']) {
            $flashMessage = $this->configHelper->getFlashFromReturnCode($processedResponse['result']['title']);
            $this->addFlash($flashMessage[0], $flashMessage[1]);
        }

        return $this->redirectToRoute('sylius_shop_order_thank_you', [], Response::HTTP_SEE_OTHER);
    }
}
