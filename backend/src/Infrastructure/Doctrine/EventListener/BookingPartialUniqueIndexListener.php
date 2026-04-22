<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Mirrors the partial UNIQUE index on barbershop_bookings (stylist_id, start_time)
 * into Doctrine's generated schema as a plain UNIQUE index, so that schema-diff
 * against SQLite stops proposing to drop it. The real index is created with a
 * WHERE clause by migration Version20260421180741 — SQLite executes the partial
 * variant, but DBAL's SQLite introspection cannot read WHERE, so both sides of
 * the diff align as unconditional indexes and the comparator sees a match.
 */
final class BookingPartialUniqueIndexListener implements EventSubscriber
{
    private const TABLE_NAME = 'barbershop_bookings';
    private const INDEX_NAME = 'UNIQ_BOOKINGS_STYLIST_START';
    private const COLUMNS = ['stylist_id', 'start_time'];

    /**
     * @return list<string>
     */
    public function getSubscribedEvents(): array
    {
        return [ToolEvents::postGenerateSchema];
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(self::TABLE_NAME);

        if ($table->hasIndex(self::INDEX_NAME)) {
            return;
        }

        $table->addUniqueIndex(
            columnNames: self::COLUMNS,
            indexName: self::INDEX_NAME,
        );
    }
}
