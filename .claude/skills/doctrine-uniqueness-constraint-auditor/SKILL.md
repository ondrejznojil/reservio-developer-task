---
name: doctrine-uniqueness-constraint-auditor
description: Use when a Doctrine/PHP codebase persists entities with a business uniqueness invariant ("no two X with same Y under condition Z") and the invariant may only be enforced in application code, not at the database. Triggers on production reports of occasional duplicate rows, SELECT-then-INSERT patterns in command handlers, "race condition" or "TOCTOU" discussion, code review of unique-constraint-adjacent migrations, or adding a new entity where double-booking / double-submission would corrupt state.
---

# Doctrine Uniqueness Constraint Auditor

## Overview

Application-level uniqueness checks are never atomic. A command handler that does
`if (!$repo->existsBy(...)) { $em->persist(...); $em->flush(); }` leaves a window
between the SELECT and the INSERT where a concurrent request can insert the same
row — classic TOCTOU. The defining symptom is **"it happens occasionally, not every
time"**. The only layer that can close this window is the database.

This skill is the audit: identify the business invariant, check whether it's backed
at the DB level, pick the right constraint for it, and wire it into Doctrine in a
way that doesn't create standing schema-diff noise.

## When to audit

Red-flag symptoms — audit immediately:

- **Occasional duplicates in production** (same user, same slot, same order — only sometimes)
- **"Race condition" / "TOCTOU" / "double-submit"** in a bug report, PR, or discussion
- A command handler that does **`findOneBy(...)` / `existsBy(...)` followed by `persist`** without an explicit lock
- A new or existing migration for an entity with a business-unique field that **does not add UNIQUE or EXCLUDE**
- An ORM `findOrCreate` / "upsert" helper that isn't using DB-level upsert (`ON CONFLICT`, `INSERT … ON DUPLICATE KEY`)

Don't use for:
- **Non-uniqueness invariants** — balance, quota, inventory counts need row locks + version columns, not UNIQUE
- **Single-writer systems** — a queue consumer with guaranteed serialized processing; the constraint is still nice to have but not urgent
- **Framework-specific naming conventions** — belongs in project docs, not here

## The audit, step by step

**1. State the invariant in one sentence.** In plain English: "No two `Booking` rows exist with the same `stylist_id` and `start_time`, unless one of them has `status = 'rejected'`." If you can't state it, there's no invariant to enforce.

**2. Search for existing enforcement.** Grep the migration directory and the Doctrine XML:

```bash
grep -rn "UNIQUE\|unique-constraint\|UNIQUE INDEX\|EXCLUDE USING" backend/migrations backend/config/doctrine
```

If the invariant appears, confirm its predicate matches the one you stated. If it doesn't appear, continue.

**3. Choose the constraint type** — see the table below.

**4. Install it** — via Doctrine XML when Doctrine supports the dialect, via raw SQL migration when it doesn't. See "Install it" per variant.

**5. Translate the exception at the handler boundary.** The DB raises `UniqueConstraintViolationException`; the user shouldn't see that. Catch and re-throw a domain exception that the GraphQL / HTTP layer knows how to render.

**6. Add a regression test** — not "happy path", but the race-reflecting scenario. See "Integration test template".

## Choose the right constraint

| Invariant shape | Constraint | Works on |
|---|---|---|
| Applies to every row | Plain `UNIQUE` | All dialects, Doctrine-native |
| Applies only under some predicate (e.g., `status != 'rejected'`) | Partial `UNIQUE` with `WHERE` | Postgres (Doctrine-native), SQLite (raw SQL + listener), **not MySQL** |
| Same as above, on MySQL | Generated column + plain `UNIQUE` | MySQL 5.7+, Postgres, SQLite ≥ 3.31 |
| "No overlapping intervals" (ranges, not equal keys) | `EXCLUDE USING gist` | Postgres only |
| None of the above fits (complex cross-table) | Application-level advisory lock per aggregate | All dialects, last resort |

### Plain UNIQUE

Doctrine XML in the entity `.dcm.xml`:

```xml
<entity name="App\Domain\User\Entity\User" table="users">
    <id name="id" type="uuid" />
    <field name="email" type="string" />
    <unique-constraints>
        <unique-constraint name="UNIQ_USERS_EMAIL" columns="email" />
    </unique-constraints>
</entity>
```

### Partial UNIQUE on Postgres

Doctrine-native via `<options>`:

```xml
<unique-constraints>
    <unique-constraint name="UNIQ_BOOKINGS_STYLIST_START" columns="stylist_id,start_time">
        <options>
            <option name="where">status != 'rejected'</option>
        </options>
    </unique-constraint>
</unique-constraints>
```

Doctrine's Postgres platform emits the `WHERE` clause and the Postgres schema manager introspects it, so the schema-diff round-trips cleanly.

### Partial UNIQUE on SQLite — raw SQL + listener

`AbstractPlatform::supportsPartialIndexes()` returns `false` for `SqlitePlatform`, and `SqliteSchemaManager` does not parse `WHERE` off existing partial indexes. Consequences:

1. The Doctrine-native `<options><option name="where">…` approach **silently drops the WHERE** on SQLite.
2. If you add the partial index via raw SQL anyway, every `doctrine:migrations:diff` proposes to drop it (introspected side sees a plain UNIQUE; mappings side sees nothing).

Workaround: raw SQL in the migration **plus** a `postGenerateSchema` listener that adds a plain UNIQUE with the same name into `toSchema`. Both sides of the comparator now agree (both WHERE-blind) and the diff stays quiet.

Migration:

```php
public function up(Schema $schema): void
{
    // Guard: refuse to create UNIQUE against pre-existing duplicates.
    $dupes = (int) $this->connection->fetchOne(
        'SELECT COUNT(*) FROM ('
        . 'SELECT 1 FROM bookings '
        . "WHERE status != 'rejected' "
        . 'GROUP BY stylist_id, start_time HAVING COUNT(*) > 1)'
    );
    $this->abortIf(
        $dupes > 0,
        "{$dupes} duplicate (stylist_id, start_time) group(s) exist with non-rejected status. "
        . 'Inspect with: SELECT stylist_id, start_time, COUNT(*) FROM bookings '
        . "WHERE status != 'rejected' GROUP BY stylist_id, start_time HAVING COUNT(*) > 1;"
    );

    $this->addSql(
        'CREATE UNIQUE INDEX UNIQ_BOOKINGS_STYLIST_START '
        . 'ON bookings (stylist_id, start_time) '
        . "WHERE status != 'rejected'"
    );
}
```

Listener (register as a Doctrine `EventSubscriber`; Nettrine auto-wires any `EventSubscriber` in the container):

```php
namespace App\Infrastructure\Doctrine\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

final class BookingPartialUniqueIndexListener implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [ToolEvents::postGenerateSchema];
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        if (!$schema->hasTable('bookings')) {
            return;
        }
        $table = $schema->getTable('bookings');
        if ($table->hasIndex('UNIQ_BOOKINGS_STYLIST_START')) {
            return;
        }
        $table->addUniqueIndex(
            columnNames: ['stylist_id', 'start_time'],
            indexName: 'UNIQ_BOOKINGS_STYLIST_START',
        );
    }
}
```

The listener adds a **plain** UNIQUE (no WHERE). That's fine — SQLite introspection produces the same plain shape. Migrations (not schema-tool) remain the source of truth for the real DDL.

### Partial UNIQUE on MySQL — generated column

MySQL has no native partial unique index. Encode the predicate in a computed column whose value is `NULL` when the row should be ignored (NULLs don't participate in UNIQUE):

```sql
ALTER TABLE bookings
  ADD COLUMN active_slot_key VARCHAR(96)
    GENERATED ALWAYS AS (
        CASE WHEN status != 'rejected'
             THEN CONCAT(stylist_id, '|', start_time)
        END
    ) STORED;
CREATE UNIQUE INDEX UNIQ_BOOKINGS_STYLIST_START ON bookings (active_slot_key);
```

Works on Postgres and SQLite ≥ 3.31 too, if you prefer not to maintain two schemas.

### EXCLUDE for overlap invariants (Postgres only)

Invariant: "no two bookings for the same stylist with overlapping `[start, end)` intervals, except rejected ones."

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE bookings
ADD CONSTRAINT bookings_no_overlap EXCLUDE USING gist (
    stylist_id WITH =,
    tstzrange(start_time, end_time) WITH &&
) WHERE (status != 'rejected');
```

Keep in raw migration SQL — no Doctrine-native mapping. Document it in the entity docblock so readers don't miss it.

### Advisory lock fallback

When the invariant spans tables or can't be expressed as a single-row predicate:

```php
$this->connection->executeStatement(
    'SELECT pg_advisory_xact_lock(hashtext(?))',
    ["booking:stylist:{$stylistId}"],
);
// … check-and-insert within the transaction …
```

The lock is released at transaction commit/rollback. Less elegant than declarative constraints, but correct.

## Handler-level translation

```php
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

try {
    $this->bookingRepository->save($booking);
} catch (UniqueConstraintViolationException) {
    throw new SlotAlreadyBookedException();
}
```

Annotate the contract so callers can't forget:

```php
// BookingRepositoryInterface
/** @throws UniqueConstraintViolationException */
public function save(Booking $booking): void;

// CreateBookingCommandHandler
/** @throws SlotAlreadyBookedException */
public function handle(CreateBookingCommand $command): CommandResult;
```

At the GraphQL / HTTP boundary, catch the **specific** domain exception only — not `\DomainException` or `\Exception`. A broad catch swallows unrelated bugs as "slot already booked" errors.

## Integration test template

The test that catches a regression is not "create a booking, check it exists." It is the race-reflecting scenario plus the predicate-sensitive cases:

```php
public function testDuplicateSlotThrows(): void
{
    $cmd = $this->buildCreateCommand(); // same stylist, same start_time
    $this->handler->handle($cmd);

    $this->expectException(SlotAlreadyBookedException::class);
    $this->handler->handle($cmd);
}

public function testRejectedSlotIsReBookable(): void
{
    $cmd = $this->buildCreateCommand();
    $first = $this->handler->handle($cmd);
    $this->rejectHandler->handle(new RejectBookingCommand(
        bookingId: $first->aggregateId,
        stylistId: /* … */,
    ));
    $second = $this->handler->handle($cmd); // must succeed
    self::assertNotSame($first->aggregateId, $second->aggregateId);
}

public function testConfirmedSlotBlocksRebooking(): void
{
    // symmetric to above, but confirm instead of reject — must still throw
}

public function testDifferentStylistSameTimeSucceeds(): void { /* … */ }
public function testSameStylistDifferentTimeSucceeds(): void { /* … */ }
```

The last four catch a regression where someone weakens the predicate (e.g., drops the status clause) or narrows the constraint to one stylist.

## Common mistakes

| Mistake | Why it fails | Fix |
|---|---|---|
| App-level `findOneBy` + `persist` without TX + row lock | TOCTOU — window between SELECT and INSERT | Push invariant to the DB |
| Transaction without `SELECT … FOR UPDATE` | Non-locking read still sees stale state; second writer proceeds | Add the lock, or use a declarative constraint |
| Plain UNIQUE when the invariant is conditional | Incorrectly blocks legitimate rows (a rejected slot stays "occupied" forever) | Use a partial UNIQUE |
| Partial UNIQUE via Doctrine `<option name="where">` on SQLite | Doctrine silently drops the WHERE | Raw SQL migration + `postGenerateSchema` listener |
| Migration adds UNIQUE without a duplicate-count pre-check | Runs in dev, fails mid-migration in prod | `abortIf` on a `GROUP BY … HAVING COUNT(*) > 1` query |
| Catch `\Exception` / `\DomainException` at the mutation | Hides unrelated failures as "conflict" | Catch the specific domain exception only |
| Missing `@throws` on handler / repository | Static analysis (PHPStan, Psalm) misses the path | Annotate both the repository's `save()` and the handler's `handle()` |
| "We filter duplicates on the read side" (e.g., `GetAvailableSlotsQueryHandler`) | Read-side filtering prevents bad UI, not bad writes; a stale query still lets a bad write through | Read-side filter is necessary but not sufficient — keep it and add the DB constraint |

## Portability notes

- **Moving SQLite → Postgres**: replace the raw-SQL partial-index migration with a Doctrine-native `<unique-constraint>` + `<options><option name="where">…`. Remove the `postGenerateSchema` listener. Consider upgrading to `EXCLUDE USING gist` if you need overlap (not just exact-match) protection.
- **Moving SQLite/Postgres → MySQL**: switch to the generated-column pattern. The listener is unnecessary on MySQL.
- **Staying on SQLite and needing overlap protection**: no declarative option. Use application-level `BEGIN IMMEDIATE` + range check inside the handler's transaction.

## Red flags — stop and audit

If any of the following appear during code review, pause and run this skill:

- `findOneBy(...)` immediately followed by `persist(...)` or `new Entity(...)` in a command handler
- A migration that creates a table where a field "should obviously be unique" with no `UNIQUE` added
- "It happens sometimes" / "race condition" / "TOCTOU" anywhere in a PR description or bug report
- `UniqueConstraintViolationException` being caught and ignored or logged-only
- A "check-then-create" service that isn't inside an explicit transaction
- Doctrine schema-diff proposing a `DROP INDEX` on an index a migration explicitly created — means introspection and mappings disagree; probably a partial index missing the listener workaround
