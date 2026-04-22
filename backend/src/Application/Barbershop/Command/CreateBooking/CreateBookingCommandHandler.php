<?php

declare(strict_types=1);

namespace App\Application\Barbershop\Command\CreateBooking;

use App\Application\Barbershop\Exception\SlotAlreadyBookedException;
use App\Application\CommandResult;
use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Repository\BookingRepositoryInterface;
use App\Domain\Barbershop\Repository\ServiceRepositoryInterface;
use App\Domain\Barbershop\Repository\StylistRepositoryInterface;
use App\Domain\ValueObject\UuidFactory;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class CreateBookingCommandHandler
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookingRepository,
        private readonly ServiceRepositoryInterface $serviceRepository,
        private readonly StylistRepositoryInterface $stylistRepository,
        private readonly UuidFactory $uuidFactory,
    ) {}

    /**
     * @throws \DateMalformedStringException
     * @throws SlotAlreadyBookedException
     */
    public function handle(CreateBookingCommand $command): CommandResult
    {
        $service = $this->serviceRepository->getById(
            $this->uuidFactory->fromString($command->serviceId),
        );
        $stylist = $this->stylistRepository->getById(
            $this->uuidFactory->fromString($command->stylistId),
        );

        $start = new DateTimeImmutable($command->startTime);
        $end   = $start->modify("+{$service->getDurationMinutes()} minutes");

        $booking = new Booking(
            id: $this->uuidFactory->generate(),
            service: $service,
            stylist: $stylist,
            startTime: $start,
            endTime: $end,
            customerName: $command->customerName,
            customerContact: $command->customerContact,
        );

        try {
            $this->bookingRepository->save($booking);
        } catch (UniqueConstraintViolationException) {
            throw new SlotAlreadyBookedException();
        }

        return new CommandResult($booking->getId()->toString());
    }
}
