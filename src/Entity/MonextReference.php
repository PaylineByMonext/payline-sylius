<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use MonextSyliusPlugin\Repository\MonextReferenceRepository;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Model\TimestampableTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @ORM\Entity(repositoryClass=MonextReferenceRepository::class)
 *
 * @ORM\Table(name="monext_reference")
 *
 * @UniqueEntity(fields={"token"}, ignoreNull=true)
 */
#[ORM\Entity(repositoryClass: MonextReferenceRepository::class)]
#[ORM\Table(name: 'monext_reference')]
#[UniqueEntity(fields: 'token', ignoreNull: 'token')]
class MonextReference
{
    use TimestampableTrait;

    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue
     *
     * @ORM\Column(type="integer")
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @ORM\OneToOne(targetEntity=Payment::class, cascade={"persist", "remove"})
     *
     * @ORM\JoinColumn(nullable=false)
     */
    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?PaymentInterface $payment = null;

    /**
     * @ORM\Column(length=255, nullable=true)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $token = null;

    /**
     * @ORM\Column(length=255, nullable=true)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transactionId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): ?PaymentInterface
    {
        return $this->payment;
    }

    public function setPayment(PaymentInterface $payment): static
    {
        $this->payment = $payment;

        return $this;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): static
    {
        $this->transactionId = $transactionId;

        return $this;
    }
}
