<?php

declare(strict_types=1);

namespace Tests\Support\Builder;

use App\Domain\Barbershop\Entity\Business;
use App\Domain\Barbershop\Entity\Service;
use App\Infrastructure\ValueObject\Uuid;

final class ServiceBuilder
{
    private string $name = 'Haircut';
    private int $durationMinutes = 30;
    private float $price = 20.0;
    private string $currency = 'EUR';
    private ?Business $business = null;

    public function __construct(
        private readonly ObjectBuilder $objectBuilder,
    ) {}

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withDurationMinutes(int $minutes): self
    {
        $this->durationMinutes = $minutes;
        return $this;
    }

    public function withPrice(float $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function withCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function forBusiness(Business $business): self
    {
        $this->business = $business;
        return $this;
    }

    public function build(): Service
    {
        $business = $this->business ?? $this->objectBuilder->createBusinessBuilder()->build();

        $service = new Service(
            id: Uuid::generate(),
            name: $this->name,
            durationMinutes: $this->durationMinutes,
            price: $this->price,
            currency: $this->currency,
        );
        $business->addService($service);

        $em = $this->objectBuilder->getEntityManager();
        $em->persist($service);
        $em->flush();

        return $service;
    }
}
