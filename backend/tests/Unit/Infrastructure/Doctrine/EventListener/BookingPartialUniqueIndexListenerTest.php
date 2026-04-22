<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Doctrine\EventListener;

use App\Infrastructure\Doctrine\EventListener\BookingPartialUniqueIndexListener;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use PHPUnit\Framework\TestCase;

final class BookingPartialUniqueIndexListenerTest extends TestCase
{
    private BookingPartialUniqueIndexListener $listener;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->listener = new BookingPartialUniqueIndexListener();
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    public function testSubscribesToPostGenerateSchemaOnly(): void
    {
        self::assertSame(
            [ToolEvents::postGenerateSchema],
            $this->listener->getSubscribedEvents(),
        );
    }

    public function testAddsUniqueIndexToBookingsTable(): void
    {
        $schema = new Schema();
        $this->createBookingsTable($schema);

        $this->listener->postGenerateSchema(new GenerateSchemaEventArgs(em: $this->em, schema: $schema));

        $table = $schema->getTable('barbershop_bookings');
        self::assertTrue($table->hasIndex('UNIQ_BOOKINGS_STYLIST_START'));

        $index = $table->getIndex('UNIQ_BOOKINGS_STYLIST_START');
        self::assertTrue($index->isUnique());
        self::assertSame(['stylist_id', 'start_time'], $index->getColumns());
    }

    public function testIsIdempotentWhenIndexAlreadyPresent(): void
    {
        $schema = new Schema();
        $this->createBookingsTable($schema);

        $this->listener->postGenerateSchema(new GenerateSchemaEventArgs(em: $this->em, schema: $schema));
        $this->listener->postGenerateSchema(new GenerateSchemaEventArgs(em: $this->em, schema: $schema));

        $indexes = $schema->getTable('barbershop_bookings')->getIndexes();
        $matching = array_filter(
            $indexes,
            static fn($index) => $index->getName() === 'UNIQ_BOOKINGS_STYLIST_START',
        );
        self::assertCount(1, $matching);
    }

    public function testIsNoOpWhenBookingsTableAbsent(): void
    {
        $schema = new Schema();

        $this->listener->postGenerateSchema(new GenerateSchemaEventArgs(em: $this->em, schema: $schema));

        self::assertFalse($schema->hasTable('barbershop_bookings'));
    }

    private function createBookingsTable(Schema $schema): Table
    {
        $table = $schema->createTable('barbershop_bookings');
        $table->addColumn('id', 'string');
        $table->addColumn('stylist_id', 'string');
        $table->addColumn('start_time', 'datetime');
        $table->setPrimaryKey(['id']);

        return $table;
    }
}
