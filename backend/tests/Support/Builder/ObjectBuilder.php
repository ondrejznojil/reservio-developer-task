<?php

declare(strict_types=1);

namespace Tests\Support\Builder;

use Doctrine\ORM\EntityManagerInterface;

final class ObjectBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    public function createBusinessBuilder(): BusinessBuilder
    {
        return new BusinessBuilder($this);
    }

    public function createStylistBuilder(): StylistBuilder
    {
        return new StylistBuilder($this);
    }

    public function createServiceBuilder(): ServiceBuilder
    {
        return new ServiceBuilder($this);
    }

    public function createOpeningHoursBuilder(): OpeningHoursBuilder
    {
        return new OpeningHoursBuilder($this);
    }
}
