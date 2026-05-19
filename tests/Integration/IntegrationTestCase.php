<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Base class for integration tests. Provides a real DBAL connection (SQLite
 * in-memory by default, MariaDB / MySQL when `MESSENGER_DASHBOARD_TEST_DSN`
 * is set) plus on-demand schema builders for the bundle's two tables.
 *
 * Schemas are created per test so each test gets a clean slate; SQLite's
 * in-memory DB also disappears entirely between tests. For MariaDB we drop
 * the tables we created in tearDown — no cross-test bleed.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DriverManager::getConnection($this->resolveConnectionParams());
        $this->dropManagedTables();
    }

    protected function tearDown(): void
    {
        $this->dropManagedTables();
        $this->conn->close();
        parent::tearDown();
    }

    /**
     * Create the `messenger_messages` table the Doctrine messenger transport
     * uses. Column types match what `Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection::configureSchema`
     * would emit. Hand-rolled here so tests don't depend on a booted
     * Symfony container.
     */
    protected function createMessengerMessagesTable(string $name = 'messenger_messages'): void
    {
        $schema = new Schema();
        $table = $schema->createTable($name);
        $table->addColumn('id', Types::BIGINT)->setAutoincrement(true)->setNotnull(true);
        $table->addColumn('body', Types::TEXT)->setNotnull(true);
        $table->addColumn('headers', Types::TEXT)->setNotnull(true);
        $table->addColumn('queue_name', Types::STRING)->setLength(190)->setNotnull(true);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE)->setNotnull(true);
        $table->addColumn('available_at', Types::DATETIME_MUTABLE)->setNotnull(true);
        $table->addColumn('delivered_at', Types::DATETIME_MUTABLE)->setNotnull(false);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['queue_name'], 'idx_queue_name');
        $this->createTable($table);
    }

    /**
     * Create the `messenger_dashboard_stats` table managed by the bundle's
     * own Doctrine migration. We mirror the entity mapping by hand rather
     * than running the migration so tests don't depend on a booted Doctrine
     * MigrationManager.
     */
    protected function createStatsTable(): void
    {
        $schema = new Schema();
        $table = $schema->createTable('messenger_dashboard_stats');
        $table->addColumn('id', Types::BIGINT)->setAutoincrement(true)->setNotnull(true);
        $table->addColumn('transport', Types::STRING)->setLength(190)->setNotnull(true);
        $table->addColumn('message_class', Types::STRING)->setLength(255)->setNotnull(true);
        $table->addColumn('status', Types::STRING)->setLength(16)->setNotnull(true);
        $table->addColumn('handled_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
        $table->addColumn('duration_ms', Types::INTEGER)->setNotnull(false);
        $table->addColumn('retry_count', Types::SMALLINT)->setNotnull(false);
        $table->addColumn('failure_class', Types::STRING)->setLength(255)->setNotnull(false);
        $table->addColumn('failure_message', Types::TEXT)->setNotnull(false);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['transport', 'handled_at'], 'idx_transport_handled_at');
        $table->addIndex(['transport', 'status', 'handled_at'], 'idx_transport_status_handled_at');
        $table->addIndex(['handled_at'], 'idx_handled_at');
        $this->createTable($table);
    }

    /**
     * Build a Doctrine ORM EntityManager scoped at the bundle's Entity
     * directory. Used by tests that need real persistence (StatsRecord
     * repository, audit subscriber).
     */
    protected function createEntityManager(): EntityManagerInterface
    {
        $bundleRoot = dirname(__DIR__, 2);
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [$bundleRoot . '/Entity'],
            isDevMode: true,
        );

        return new EntityManager($this->conn, $config);
    }

    protected function createManagerRegistry(EntityManagerInterface $em): ManagerRegistry
    {
        return new class ($em) extends AbstractManagerRegistry {
            public function __construct(private readonly EntityManagerInterface $em)
            {
                parent::__construct(
                    'default',
                    ['default'],
                    ['default'],
                    'default',
                    'default',
                    \Doctrine\Persistence\Proxy::class,
                );
            }

            protected function getService($name): object
            {
                return $this->em;
            }

            protected function resetService($name): void
            {
                $this->em->clear();
            }
        };
    }

    /**
     * Whether the active connection is SQLite. Some assertions need to
     * branch (e.g. SQLite is more permissive than MariaDB about LIKE
     * ESCAPE placement).
     */
    protected function isSqlite(): bool
    {
        return $this->conn->getDatabasePlatform() instanceof SqlitePlatform;
    }

    private function createTable(Table $table): void
    {
        foreach ($table->getIndexes() as $idx) {
            if ($idx->isPrimary()) {
                continue;
            }
            // Doctrine schema diff sometimes emits idx names too long for
            // certain platforms. Keep names short and explicit per-test.
        }
        foreach ($this->conn->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->conn->executeStatement($sql);
        }
    }

    private function dropManagedTables(): void
    {
        foreach (['messenger_messages', 'messenger_dashboard_stats'] as $name) {
            try {
                $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $name);
            } catch (Throwable) {
                // Connection may already be closed; ignore.
            }
        }
    }

    /**
     * Translate the env-var DSN into a DBAL connection params array. We
     * accept Doctrine-style URLs (`pdo-sqlite:///:memory:`,
     * `pdo-mysql://user:pass@host:port/dbname`) because that's the form
     * humans write, but produce the explicit driver+credentials dict that
     * modern DBAL requires.
     *
     * @return array<string, mixed>
     */
    private function resolveConnectionParams(): array
    {
        $dsn = getenv('MESSENGER_DASHBOARD_TEST_DSN');
        if (!is_string($dsn) || $dsn === '' || $dsn === 'pdo-sqlite:///:memory:') {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        if (str_starts_with($dsn, 'pdo-sqlite:///')) {
            $path = substr($dsn, strlen('pdo-sqlite:///'));

            return $path === ':memory:'
                ? ['driver' => 'pdo_sqlite', 'memory' => true]
                : ['driver' => 'pdo_sqlite', 'path' => $path];
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new RuntimeException(sprintf('Unparseable MESSENGER_DASHBOARD_TEST_DSN: %s', $dsn));
        }
        $driver = match ($parts['scheme']) {
            'pdo-mysql', 'mysql' => 'pdo_mysql',
            'pdo-pgsql', 'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
            default => throw new RuntimeException(sprintf('Unsupported scheme "%s" in DSN.', $parts['scheme'])),
        };
        $params = ['driver' => $driver];
        if (isset($parts['user'])) {
            $params['user'] = $parts['user'];
        }
        if (isset($parts['pass'])) {
            $params['password'] = $parts['pass'];
        }
        if (isset($parts['host'])) {
            $params['host'] = $parts['host'];
        }
        if (isset($parts['port'])) {
            $params['port'] = (int) $parts['port'];
        }
        if (isset($parts['path'])) {
            $params['dbname'] = ltrim($parts['path'], '/');
        }

        return $params;
    }
}
