<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Barbershop\Command\CreateBooking;

use App\Application\Barbershop\Command\ConfirmBooking\ConfirmBookingCommand;
use App\Application\Barbershop\Command\ConfirmBooking\ConfirmBookingCommandHandler;
use App\Application\Barbershop\Command\CreateBooking\CreateBookingCommand;
use App\Application\Barbershop\Command\CreateBooking\CreateBookingCommandHandler;
use App\Application\Barbershop\Command\RejectBooking\RejectBookingCommand;
use App\Application\Barbershop\Command\RejectBooking\RejectBookingCommandHandler;
use App\Application\Barbershop\Exception\SlotAlreadyBookedException;
use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Entity\Service;
use App\Domain\Barbershop\Entity\Stylist;
use App\Domain\Barbershop\Enum\BookingStatus;
use Tests\Support\IntegrationTestCase;

final class CreateBookingCommandHandlerTest extends IntegrationTestCase
{
    private CreateBookingCommandHandler $handler;
    private RejectBookingCommandHandler $rejectHandler;
    private ConfirmBookingCommandHandler $confirmHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->container->getByType(CreateBookingCommandHandler::class);
        $this->rejectHandler = $this->container->getByType(RejectBookingCommandHandler::class);
        $this->confirmHandler = $this->container->getByType(ConfirmBookingCommandHandler::class);
    }

    public function testCreateBookingOnceForEmptySlotSucceeds(): void
    {
        $stylist = $this->objectBuilder->createStylistBuilder()->build();
        $service = $this->objectBuilder->createServiceBuilder()->forBusiness($stylist->getBusiness())->build();
        $command = $this->buildCreateCommand($stylist, $service);

        $result = $this->handler->handle($command);

        self::assertNotSame('', $result->aggregateId);

        $bookings = $this->em->getRepository(Booking::class)->findAll();
        self::assertCount(1, $bookings);
        self::assertSame(BookingStatus::Pending, $bookings[0]->getStatus());
    }

    public function testCreateBookingTwiceForSameSlotThrowsSlotAlreadyBookedException(): void
    {
        $stylist = $this->objectBuilder->createStylistBuilder()->build();
        $service = $this->objectBuilder->createServiceBuilder()->forBusiness($stylist->getBusiness())->build();
        $command = $this->buildCreateCommand($stylist, $service);

        $this->handler->handle($command);

        $this->expectException(SlotAlreadyBookedException::class);
        $this->expectExceptionMessage('This slot is no longer available. Please choose another time.');

        $this->handler->handle($command);
    }

    public function testCreateBookingAfterPreviousWasRejectedSucceeds(): void
    {
        $stylist = $this->objectBuilder->createStylistBuilder()->build();
        $service = $this->objectBuilder->createServiceBuilder()->forBusiness($stylist->getBusiness())->build();
        $command = $this->buildCreateCommand($stylist, $service);

        $firstResult = $this->handler->handle($command);

        $this->rejectHandler->handle(new RejectBookingCommand(
            bookingId: $firstResult->aggregateId,
            stylistId: $stylist->getId()->toString(),
            reason: 'No longer available',
        ));

        $secondResult = $this->handler->handle($command);
        self::assertNotSame($firstResult->aggregateId, $secondResult->aggregateId);

        $this->em->clear();
        $bookings = $this->em->getRepository(Booking::class)->findAll();
        self::assertCount(2, $bookings);

        $statuses = array_map(static fn(Booking $b) => $b->getStatus(), $bookings);
        self::assertContains(BookingStatus::Rejected, $statuses);
        self::assertContains(BookingStatus::Pending, $statuses);
    }

    public function testCreateBookingAfterPreviousWasConfirmedThrowsSlotAlreadyBookedException(): void
    {
        $stylist = $this->objectBuilder->createStylistBuilder()->build();
        $service = $this->objectBuilder->createServiceBuilder()->forBusiness($stylist->getBusiness())->build();
        $command = $this->buildCreateCommand($stylist, $service);

        $firstResult = $this->handler->handle($command);

        $this->confirmHandler->handle(new ConfirmBookingCommand(
            bookingId: $firstResult->aggregateId,
            stylistId: $stylist->getId()->toString(),
        ));

        $this->expectException(SlotAlreadyBookedException::class);

        $this->handler->handle($command);
    }

    public function testCreateBookingForDifferentStylistAtSameTimeSucceeds(): void
    {
        $business = $this->objectBuilder->createBusinessBuilder()->build();
        $stylistA = $this->objectBuilder->createStylistBuilder()
            ->forBusiness($business)
            ->withName('Stylist A')
            ->build();
        $stylistB = $this->objectBuilder->createStylistBuilder()
            ->forBusiness($business)
            ->withName('Stylist B')
            ->build();
        $service = $this->objectBuilder->createServiceBuilder()->forBusiness($business)->build();

        $this->handler->handle(new CreateBookingCommand(
            stylistId: $stylistA->getId()->toString(),
            serviceId: $service->getId()->toString(),
            startTime: '2026-05-04T10:00:00+00:00',
            customerName: 'Alice',
            customerContact: 'alice@example.com',
        ));

        $this->handler->handle(new CreateBookingCommand(
            stylistId: $stylistB->getId()->toString(),
            serviceId: $service->getId()->toString(),
            startTime: '2026-05-04T10:00:00+00:00',
            customerName: 'Bob',
            customerContact: 'bob@example.com',
        ));

        self::assertCount(2, $this->em->getRepository(Booking::class)->findAll());
    }

    public function testCreateBookingForSameStylistAtDifferentTimeSucceeds(): void
    {
        $stylist = $this->objectBuilder->createStylistBuilder()->build();
        $service = $this->objectBuilder->createServiceBuilder()->forBusiness($stylist->getBusiness())->build();

        $this->handler->handle(new CreateBookingCommand(
            stylistId: $stylist->getId()->toString(),
            serviceId: $service->getId()->toString(),
            startTime: '2026-05-04T10:00:00+00:00',
            customerName: 'Alice',
            customerContact: 'alice@example.com',
        ));

        $this->handler->handle(new CreateBookingCommand(
            stylistId: $stylist->getId()->toString(),
            serviceId: $service->getId()->toString(),
            startTime: '2026-05-04T11:00:00+00:00',
            customerName: 'Bob',
            customerContact: 'bob@example.com',
        ));

        self::assertCount(2, $this->em->getRepository(Booking::class)->findAll());
    }

    private function buildCreateCommand(Stylist $stylist, Service $service): CreateBookingCommand
    {
        return new CreateBookingCommand(
            stylistId: $stylist->getId()->toString(),
            serviceId: $service->getId()->toString(),
            startTime: '2026-05-04T10:00:00+00:00',
            customerName: 'John Doe',
            customerContact: 'john@example.com',
        );
    }
}
