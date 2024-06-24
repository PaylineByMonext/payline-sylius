<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Helpers;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\RouterInterface;

class ConfigHelper
{
    public const FIELD_API_KEY = 'api_key';
    public const FIELD_ENVIRONMENT = 'environment';
    public const FIELD_CAPTURE_TYPE = 'capture_type';
    public const FIELD_POINT_OF_SALE = 'point_of_sale';
    public const FIELD_CONTRACTS_NUMBER = 'contracts_numbers';
    public const FIELD_MANUAL_CAPTURE_TRANSITION = 'manual_capture_transition';

    public const FIELD_VALUE_ENVIRONMENT_HOMOLOGATION = 'https://api-sandbox.retail.monext.com/v1/';
    public const FIELD_VALUE_ENVIRONMENT_PRODUCTION = 'TODO';

    public const FIELD_VALUE_CAPTURE_TYPE_AUTO = 'AUTOMATIC';
    public const FIELD_VALUE_CAPTURE_TYPE_MANUAL = 'MANUAL';

    public function __construct(
        private UrlHelper $urlHelper,
        private RouterInterface $router,
        private bool $unsecuredUrls = false
    ) {
    }

    /**
     * Get array configuration from payment object and validates it.
     *
     * @return array<string, string>
     *
     * @throws \Exception
     */
    public function getGatewayConfig(PaymentInterface $payment): array
    {
        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();
        $gatewayConfig = $method->getGatewayConfig()->getConfig();

        if (!$this->validate($gatewayConfig)) {
            throw new \Exception('Missing required parameters for API configuration.');
        }

        return $gatewayConfig;
    }

    /**
     * Check if given configuration is valid for API usage.
     *
     * @param array<string, string> $config
     */
    public function validate(array $config): bool
    {
        return isset(
            $config[self::FIELD_API_KEY],
            $config[self::FIELD_ENVIRONMENT],
            $config[self::FIELD_POINT_OF_SALE],
            $config[self::FIELD_CONTRACTS_NUMBER],
            $config[self::FIELD_CAPTURE_TYPE]
        );
    }

    /**
     * Get a path based on current hostname or channel info as fallback.
     */
    public function getUrl(string $pathName, ChannelInterface $channel, string $localeCode = ''): string
    {
        $path = $this->router->generate(
            $pathName,
            '' !== $localeCode ? ['_locale' => $localeCode] : []
        );

        // Can't use channel info because it used the private port instead of public one in containers.
        $hostname = $_ENV['HTTP_HOST'] ?? $channel->getHostname();

        if (null !== $channel->getHostname()) {
            return ($this->unsecuredUrls ? 'http://' : 'https://').$hostname.$path;
        }

        return $this->urlHelper->getAbsoluteUrl($path);
    }

    /**
     * Convert Monext return code to a Sylius state.
     * See: https://docs.monext.fr/display/DT/Return+codes.
     */
    public function convertPaymentState(string $monextState, string $captureType): string
    {
        return match ($monextState) {
            'ACCEPTED' => self::FIELD_VALUE_CAPTURE_TYPE_MANUAL === $captureType ? PaymentInterface::STATE_AUTHORIZED : PaymentInterface::STATE_COMPLETED,
            'ERROR', 'REFUSED' => PaymentInterface::STATE_FAILED,
            'INPROGRESS', 'ONHOLD_PARTNER', 'PENDING_RISK' => PaymentInterface::STATE_PROCESSING,
            'CANCELLED' => PaymentInterface::STATE_CANCELLED,
            default => PaymentInterface::STATE_UNKNOWN,
        };
    }

    /**
     * Convert State name to transition name.
     */
    public function convertPaymentTransition(string $state): string
    {
        return match ($state) {
            PaymentInterface::STATE_COMPLETED => 'complete',
            PaymentInterface::STATE_AUTHORIZED => 'authorize',
            PaymentInterface::STATE_FAILED => 'fail',
            PaymentInterface::STATE_PROCESSING => 'process',
            PaymentInterface::STATE_CANCELLED => 'cancel',
            default => ''
        };
    }

    /**
     * Get a flash message based on Monext return code.
     *
     * @return array<int, string>
     */
    public function getFlashFromReturnCode(string $returnCode): array
    {
        return match ($returnCode) {
            'INPROGRESS', 'ONHOLD_PARTNER', 'PENDING_RISK' => ['info', 'monext.return.in_progress'],
            'CANCELLED' => ['info', 'monext.return.cancelled'],
            'ERROR' => ['error', 'monext.return.error'],
            'REFUSED' => ['error', 'monext.return.refused'],
            default => ['error', 'monext.return.unknown']
        };
    }
}
