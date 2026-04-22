<?php

declare(strict_types=1);

namespace Tests\Support\Builder;

use App\Domain\Barbershop\Entity\Business;
use App\Infrastructure\ValueObject\Uuid;

final class BusinessBuilder
{
    private string $name = 'Test Barbershop';
    private string $slug;

    public function __construct(
        private readonly ObjectBuilder $objectBuilder,
    ) {
        $this->slug = 'test-barbershop-' . bin2hex(random_bytes(4));
    }

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function build(): Business
    {
        $business = new Business(
            id: Uuid::generate(),
            name: $this->name,
            slug: $this->slug,
        );

        $em = $this->objectBuilder->getEntityManager();
        $em->persist($business);
        $em->flush();

        return $business;
    }
}
