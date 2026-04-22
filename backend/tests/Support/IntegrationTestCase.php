<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\TestCase;
use Tests\Bootstrap;
use Tests\Support\Builder\ObjectBuilder;

abstract class IntegrationTestCase extends TestCase
{
    private static ?Container $sharedContainer = null;
    private static bool $migrationsRan = false;

    protected Container $container;
    protected EntityManagerInterface $em;
    protected ObjectBuilder $objectBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sharedContainer !== null) {
            $sharedEm = self::$sharedContainer->getByType(EntityManagerInterface::class);
            if (!$sharedEm->isOpen()) {
                self::$sharedContainer = null;
                self::$migrationsRan = false;
            }
        }

        if (self::$sharedContainer === null) {
            self::$sharedContainer = Bootstrap::boot();
        }

        $this->container = self::$sharedContainer;
        $this->em = $this->container->getByType(EntityManagerInterface::class);

        if (!self::$migrationsRan) {
            $this->migrateToLatest();
            self::$migrationsRan = true;
        }

        // clear() must precede truncate() — otherwise the EM identity map keeps stale
        // references to entities whose rows have been deleted.
        $this->em->clear();
        $this->truncateAllTables();

        $this->objectBuilder = $this->container->getByType(ObjectBuilder::class);
    }

    private function migrateToLatest(): void
    {
        $df = $this->container->getByType(DependencyFactory::class);

        $df->getMetadataStorage()->ensureInitialized();

        $version = $df->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $df->getMigrationPlanCalculator()->getPlanUntilVersion($version);

        if ($this->planIsEmpty($plan)) {
            return;
        }

        $df->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    private function planIsEmpty(MigrationPlanList $plan): bool
    {
        return count($plan->getItems()) === 0;
    }

    private function truncateAllTables(): void
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ($metadata as $meta) {
            if ($meta->isMappedSuperclass || $meta->subClasses !== []) {
                continue;
            }
            $connection->executeStatement($platform->getTruncateTableSQL(
                tableName: $meta->getTableName(),
                cascade: true,
            ));
        }
    }
}
