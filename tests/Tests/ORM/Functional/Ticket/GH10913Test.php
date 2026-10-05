<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_filter;
use function array_values;
use function strpos;

class GH10913Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH10913Entity::class,
        ]);
    }

    public function testExample1(): void
    {
        [$a, $b, $c] = $this->createEntities(3);

        $c->ref = $b;
        $b->odc = $a;

        $this->_em->persist($a);
        $this->_em->persist($b);
        $this->_em->persist($c);
        $this->_em->flush();

        $this->_em->remove($a);
        $this->_em->remove($b);
        $this->_em->remove($c);

        $this->flushAndAssertNumberOfDeleteQueries(3);
    }

    public function testExample2(): void
    {
        [$a, $b, $c] = $this->createEntities(3);

        $a->odc = $b;
        $b->odc = $a;
        $c->ref = $b;

        $this->_em->persist($a);
        $this->_em->persist($b);
        $this->_em->persist($c);
        $this->_em->flush();

        $this->_em->remove($a);
        $this->_em->remove($b);
        $this->_em->remove($c);

        $this->flushAndAssertNumberOfDeleteQueries(3);
    }

    public function testExample3(): void
    {
        [$a, $b, $c] = $this->createEntities(3);

        $a->odc = $b;
        $a->ref = $c;
        $c->ref = $b;
        $b->odc = $a;

        $this->_em->persist($a);
        $this->_em->persist($b);
        $this->_em->persist($c);
        $this->_em->flush();

        $aId = $a->id;
        $bId = $b->id;
        $cId = $c->id;

        $this->_em->remove($a);
        $this->_em->remove($b);
        $this->_em->remove($c);

        // The nullable ref associations form a cycle around the CASCADE group
        // {a, b}: it is broken at one nullable edge by an UPDATE that sets the
        // foreign key to NULL, then all three rows are deleted.
        $this->flushAndAssertNumberOfDeleteAndForeignKeyNullUpdateQueries(3, 1);

        $this->_em->clear();

        self::assertNull($this->_em->find(GH10913Entity::class, $aId));
        self::assertNull($this->_em->find(GH10913Entity::class, $bId));
        self::assertNull($this->_em->find(GH10913Entity::class, $cId));
    }

    public function testExample4(): void
    {
        [$a, $b, $c, $d] = $this->createEntities(4);

        $a->ref = $b;
        $b->odc = $c;
        $c->odc = $b;
        $d->ref = $c;

        $this->_em->persist($a);
        $this->_em->persist($b);
        $this->_em->persist($c);
        $this->_em->persist($d);
        $this->_em->flush();

        $this->_em->remove($b);
        $this->_em->remove($c);
        $this->_em->remove($d);
        $this->_em->remove($a);

        $this->flushAndAssertNumberOfDeleteQueries(4);
    }

    private function flushAndAssertNumberOfDeleteQueries(int $expectedCount): void
    {
        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        $this->_em->flush();

        $queries = array_values(array_filter($queryLog->queries, static function (array $entry): bool {
            return strpos($entry['sql'], 'DELETE') === 0;
        }));

        self::assertCount($expectedCount, $queries);
    }

    private function flushAndAssertNumberOfDeleteAndForeignKeyNullUpdateQueries(
        int $expectedDeleteCount,
        int $expectedUpdateCount,
    ): void {
        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        $this->_em->flush();

        $deletes = array_values(array_filter($queryLog->queries, static function (array $entry): bool {
            return strpos($entry['sql'], 'DELETE') === 0;
        }));
        $updates = array_values(array_filter($queryLog->queries, static function (array $entry): bool {
            return strpos($entry['sql'], 'UPDATE') === 0;
        }));

        self::assertCount($expectedDeleteCount, $deletes);
        self::assertCount($expectedUpdateCount, $updates);
    }

    /** @return list<GH10913Entity> */
    private function createEntities(int $count = 1): array
    {
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[] = new GH10913Entity();
        }

        return $result;
    }
}

#[ORM\Entity]
class GH10913Entity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public GH10913Entity $odc;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    public GH10913Entity $ref;
}
