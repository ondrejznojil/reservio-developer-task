<?php

declare(strict_types=1);

namespace Tests\Support\Builder;

use App\Domain\Barbershop\Entity\Business;
use App\Domain\Barbershop\Entity\OpeningHours;
use App\Domain\Barbershop\Enum\DayOfWeek;
use App\Infrastructure\ValueObject\Uuid;

final class OpeningHoursBuilder
{
    private DayOfWeek $dayOfWeek = DayOfWeek::Monday;
    private string $openFrom = '09:00';
    private string $openTo = '17:00';
    private ?Business $business = null;

    public function __construct(
        private readonly ObjectBuilder $objectBuilder,
    ) {}

    public function onDay(DayOfWeek $day): self
    {
        $this->dayOfWeek = $day;
        return $this;
    }

    public function withOpenFrom(string $time): self
    {
        $this->openFrom = $time;
        return $this;
    }

    public function withOpenTo(string $time): self
    {
        $this->openTo = $time;
        return $this;
    }

    public function forBusiness(Business $business): self
    {
        $this->business = $business;
        return $this;
    }

    public function build(): OpeningHours
    {
        $business = $this->business ?? $this->objectBuilder->createBusinessBuilder()->build();

        $openingHours = new OpeningHours(
            id: Uuid::generate(),
            dayOfWeek: $this->dayOfWeek,
            openFrom: $this->openFrom,
            openTo: $this->openTo,
        );
        $business->addOpeningHours($openingHours);

        $em = $this->objectBuilder->getEntityManager();
        $em->persist($openingHours);
        $em->flush();

        return $openingHours;
    }
}
