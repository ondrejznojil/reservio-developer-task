<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421180741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent duplicate bookings via partial UNIQUE index on (stylist_id, start_time) '
            . "where status != 'rejected'. Written as raw SQL because Doctrine's SQLite platform "
            . 'does not emit the WHERE clause from <unique-constraint> options '
            . '(AbstractPlatform::supportsPartialIndexes() returns false for SQLite) and '
            . 'SqliteSchemaManager cannot introspect the WHERE condition of an existing partial '
            . 'index. A future schema-diff will therefore propose dropping this index — ignore '
            . 'that proposal. If the project ever moves to Postgres, migrate this into '
            . 'Booking.dcm.xml as <unique-constraint> with <options><option name="where">... '
            . 'and drop this migration.';
    }

    public function up(Schema $schema): void
    {
        $duplicateGroups = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ('
            . 'SELECT 1 FROM barbershop_bookings '
            . "WHERE status != 'rejected' "
            . 'GROUP BY stylist_id, start_time HAVING COUNT(*) > 1'
            . ')'
        );
        $this->abortIf(
            $duplicateGroups > 0,
            "Cannot create partial UNIQUE index on barbershop_bookings: {$duplicateGroups} duplicate "
            . '(stylist_id, start_time) group(s) with non-rejected status exist. '
            . 'Inspect with: SELECT stylist_id, start_time, COUNT(*) FROM barbershop_bookings '
            . "WHERE status != 'rejected' GROUP BY stylist_id, start_time HAVING COUNT(*) > 1; "
            . 'Resolve by rejecting or deleting the extras, then retry the migration.'
        );

        $this->addSql(
            'CREATE UNIQUE INDEX UNIQ_BOOKINGS_STYLIST_START '
            . 'ON barbershop_bookings (stylist_id, start_time) '
            . "WHERE status != 'rejected'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_BOOKINGS_STYLIST_START');
    }
}
