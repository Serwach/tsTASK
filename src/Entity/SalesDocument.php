<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SalesDocumentRepository::class)]
class SalesDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $contractorId;

    #[ORM\Column]
    private int $createdBy;

    #[ORM\Column(enumType: SalesDocumentType::class)]
    private SalesDocumentType $type;

    #[ORM\Column(enumType: SalesDocumentStatus::class)]
    private SalesDocumentStatus $status;

    #[ORM\Column(nullable: true)]
    private ?int $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $rejectedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $parentQuoteId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sellerSnapshot = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContractorId(): int
    {
        return $this->contractorId;
    }

    public function setContractorId(int $contractorId): void
    {
        $this->contractorId = $contractorId;
    }

    public function getCreatedBy(): int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(int $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getType(): SalesDocumentType
    {
        return $this->type;
    }

    public function setType(SalesDocumentType $type): void
    {
        $this->type = $type;
    }

    public function getStatus(): SalesDocumentStatus
    {
        return $this->status;
    }

    public function setStatus(SalesDocumentStatus $status): void
    {
        $this->status = $status;
    }

    public function getApprovedBy(): ?int
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?int $approvedBy): void
    {
        $this->approvedBy = $approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
    }

    public function getRejectedBy(): ?int
    {
        return $this->rejectedBy;
    }

    public function setRejectedBy(?int $rejectedBy): void
    {
        $this->rejectedBy = $rejectedBy;
    }

    public function getRejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function setRejectedAt(?\DateTimeImmutable $rejectedAt): void
    {
        $this->rejectedAt = $rejectedAt;
    }

    public function getParentQuoteId(): ?int
    {
        return $this->parentQuoteId;
    }

    public function setParentQuoteId(?int $parentQuoteId): void
    {
        $this->parentQuoteId = $parentQuoteId;
    }

    public function getSellerSnapshot(): ?array
    {
        return $this->sellerSnapshot;
    }

    public function setSellerSnapshot(?array $sellerSnapshot): void
    {
        $this->sellerSnapshot = $sellerSnapshot;
    }
}
