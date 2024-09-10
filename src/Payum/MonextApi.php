<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Payum;

final class MonextApi
{
    public function __construct(
        private string $apiKey,
        private string $pointOfSale,
        private string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getPointOfSale(): string
    {
        return $this->pointOfSale;
    }
}
