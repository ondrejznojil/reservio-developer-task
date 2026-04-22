<?php

declare(strict_types=1);

namespace Tests\Support\Builder;

use App\Domain\Barbershop\Entity\Business;
use App\Domain\Barbershop\Entity\Stylist;
use App\Infrastructure\ValueObject\Uuid;

final class StylistBuilder
{
    private string $name = 'Test Stylist';
    private ?string $photoUrl = null;
    private ?Business $business = null;

    public function __construct(
        private readonly ObjectBuilder $objectBuilder,
    ) {}

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withPhotoUrl(?string $photoUrl): self
    {
        $this->photoUrl = $photoUrl;
        return $this;
    }

    public function forBusiness(Business $business): self
    {
        $this->business = $business;
        return $this;
    }

    public function build(): Stylist
    {
        $business = $this->business ?? $this->objectBuilder->createBusinessBuilder()->build();

        $stylist = new Stylist(
            id: Uuid::generate(),
            name: $this->name,
            photoUrl: $this->photoUrl,
        );
        $business->addStylist($stylist);

        $em = $this->objectBuilder->getEntityManager();
        $em->persist($stylist);
        $em->flush();

        return $stylist;
    }
}
