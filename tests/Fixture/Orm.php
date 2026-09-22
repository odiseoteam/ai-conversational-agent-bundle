<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\ConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\NullConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmMemoryStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSessionStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSpendLedger;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestConversation;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestMemoryFact;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestMessage;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestSpendEntry;

/**
 * An entity manager with the core's XML mappings and the test entities that extend them,
 * schema created: what the ORM stores need and nothing of Symfony.
 *
 * SQLite in memory by default, so the suite needs no server. AGENT_TEST_DATABASE_URL points it
 * at a real one instead; the CI matrix runs the same tests on MySQL and on PostgreSQL, where
 * the JSON columns, the optimistic lock and the collation of the memory search behave like
 * they will in a shop.
 */
final class Orm
{
    public static function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createConfiguration(isDevMode: true);
        $chain = new MappingDriverChain();
        $chain->addDriver(new SimplifiedXmlDriver([\dirname(__DIR__, 2).'/config/orm' => 'Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model']), 'Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model');
        $chain->addDriver(new AttributeDriver([__DIR__.'/Entity']), 'Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity');
        $config->setMetadataDriverImpl($chain);

        $em = new EntityManager(DriverManager::getConnection(self::connection(), $config), $config);

        // A server keeps what the last test left; in-memory SQLite is new every time and the
        // drop is a no-op. SchemaTool ignores a statement that finds nothing to drop.
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $em;
    }

    /** @return array<string, mixed> */
    private static function connection(): array
    {
        $dsn = getenv('AGENT_TEST_DATABASE_URL');
        if (!\is_string($dsn) || '' === $dsn) {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        return (new DsnParser([
            'mysql' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
        ]))->parse($dsn);
    }

    public static function sessionStore(?EntityManagerInterface $em = null, int $retentionDays = 30, ?ConversationInitializer $initializer = null): OrmSessionStore
    {
        return new OrmSessionStore(
            $em ?? self::entityManager(),
            TestConversation::class,
            TestMessage::class,
            $retentionDays,
            $initializer ?? new NullConversationInitializer(),
        );
    }

    public static function memoryStore(?EntityManagerInterface $em = null): OrmMemoryStore
    {
        return new OrmMemoryStore($em ?? self::entityManager(), TestMemoryFact::class);
    }

    public static function spendLedger(?EntityManagerInterface $em = null): OrmSpendLedger
    {
        return new OrmSpendLedger($em ?? self::entityManager(), TestSpendEntry::class);
    }
}
