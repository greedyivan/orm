<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Internal\TopologicalSort\CycleDetectedException;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_filter;
use function array_values;
use function strpos;

/**
 * Deleting a cycle of entities that reference each other through foreign keys
 * used to fail with a CycleDetectedException even when every foreign key of
 * the cycle is nullable (#5665): the delete order computation treated all
 * association edges as mandatory. Following the commit order computation for
 * insertions, an edge backed by a nullable join column is now optional: the
 * cycle is broken at such an edge, and an UPDATE writing NULL to the foreign
 * key column is executed before the deletions, so the whole cycle can be
 * removed without violating any constraint.
 *
 * Cycles whose foreign keys are all non-nullable keep throwing
 * CycleDetectedException (see the nonnullable-exception-kept scenario).
 */
class GH5665Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH5665Item::class,
            GH5665Subitem::class,
            GH5665TwoLinkNode::class,
            GH5665ThreeLinkNode::class,
            GH5665MixedCycleNode::class,
            GH5665HardCycleNode::class,
            GH5665CascadeGroupEntity::class,
            GH5665InterplayOwner::class,
            GH5665InterplayNode::class,
        ]);
    }

    #[Group('gh5665-anchor-repro')]
    public function testCascadeDeleteWithSelfReferencingFeaturedItem(): void
    {
        $item = new GH5665Item();
        $sub1 = new GH5665Subitem();
        $sub2 = new GH5665Subitem();
        $item->addItem($sub1);
        $item->addItem($sub2);
        $item->setFeaturedItem($sub2);
        $this->_em->persist($item);
        $this->_em->flush();

        $itemId = $item->getId();
        $sub1Id = $sub1->getId();
        $sub2Id = $sub2->getId();

        $this->_em->remove($item);

        // Used to throw a CycleDetectedException: the deletion order graph
        // has a cycle between the item and its featured subitem.
        $this->_em->flush();

        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665Item::class, $itemId));
        self::assertNull($this->_em->find(GH5665Subitem::class, $sub1Id));
        self::assertNull($this->_em->find(GH5665Subitem::class, $sub2Id));
    }

    #[Group('gh5665-fknull-query-order')]
    public function testForeignKeyNullUpdateIsExecutedOnceAndBeforeAllDeletes(): void
    {
        $item = new GH5665Item();
        $sub1 = new GH5665Subitem();
        $sub2 = new GH5665Subitem();
        $item->addItem($sub1);
        $item->addItem($sub2);
        $item->setFeaturedItem($sub2);
        $this->_em->persist($item);
        $this->_em->flush();

        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        $this->_em->remove($item);
        $this->_em->flush();

        // Exactly one edge of the cycle is broken: exactly one UPDATE writes
        // NULL to a foreign key column of the cycle — the nullable item_id
        // column of the featured subitem (the broken edge), whose row would
        // otherwise still reference the deleted item.
        $updates = $this->queriesStartingWith('UPDATE');

        self::assertCount(1, $updates);
        self::assertStringContainsString('SET item_id = ?', $updates[0]['sql']);
        self::assertNull(array_values($updates[0]['params'])[0]);

        // The FK-null UPDATE must run before every DELETE of the flush.
        $updatePositions = $this->queryPositionsStartingWith('UPDATE');
        $deletePositions = $this->queryPositionsStartingWith('DELETE');

        self::assertCount(3, $deletePositions);
        foreach ($deletePositions as $deletePosition) {
            self::assertGreaterThan($updatePositions[0], $deletePosition);
        }
    }

    #[Group('gh5665-two-link-cycle')]
    public function testTwoLinkNullableCycleIsBroken(): void
    {
        $x      = new GH5665TwoLinkNode();
        $y      = new GH5665TwoLinkNode();
        $x->ref = $y;
        $y->ref = $x;
        $this->_em->persist($x);
        $this->_em->persist($y);
        $this->_em->flush();

        $xId = $x->id;
        $yId = $y->id;

        $this->_em->remove($x);
        $this->_em->remove($y);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665TwoLinkNode::class, $xId));
        self::assertNull($this->_em->find(GH5665TwoLinkNode::class, $yId));
    }

    #[Group('gh5665-three-link-cycle')]
    public function testThreeLinkNullableCycleIsBroken(): void
    {
        $a       = new GH5665ThreeLinkNode();
        $b       = new GH5665ThreeLinkNode();
        $c       = new GH5665ThreeLinkNode();
        $a->next = $b;
        $b->next = $c;
        $c->next = $a;
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
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665ThreeLinkNode::class, $aId));
        self::assertNull($this->_em->find(GH5665ThreeLinkNode::class, $bId));
        self::assertNull($this->_em->find(GH5665ThreeLinkNode::class, $cId));
    }

    #[Group('gh5665-mixed-nullability')]
    public function testMixedNullabilityCycleIsBrokenAtTheOnlyNullableEdge(): void
    {
        $a           = new GH5665MixedCycleNode();
        $b           = new GH5665MixedCycleNode();
        $c           = new GH5665MixedCycleNode();
        $a->id       = 1;
        $b->id       = 2;
        $c->id       = 3;
        $a->hardNext = $b;
        $b->hardNext = $c;
        // A NOT NULL foreign key needs a target row at insert time: the
        // self-reference satisfies it and takes part in no graph edge.
        $c->hardNext = $c;
        $c->softNext = $a;
        $this->_em->persist($a);
        $this->_em->persist($b);
        $this->_em->persist($c);
        $this->_em->flush();

        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        $this->_em->remove($a);
        $this->_em->remove($b);
        $this->_em->remove($c);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665MixedCycleNode::class, 1));
        self::assertNull($this->_em->find(GH5665MixedCycleNode::class, 2));
        self::assertNull($this->_em->find(GH5665MixedCycleNode::class, 3));

        // The cycle is broken at its single nullable edge: the softNext
        // foreign key of node c, nulled by exactly one UPDATE.
        $updates = $this->queriesStartingWith('UPDATE');

        self::assertCount(1, $updates);
        self::assertStringContainsString('SET softNext_id = ?', $updates[0]['sql']);
        self::assertNull(array_values($updates[0]['params'])[0]);
    }

    #[Group('gh5665-nonnullable-exception-kept')]
    public function testNonNullableCycleStillThrowsCycleDetectedException(): void
    {
        $x     = new GH5665HardCycleNode();
        $y     = new GH5665HardCycleNode();
        $x->id = 1;
        $y->id = 2;
        // A NOT NULL foreign key cannot form a cycle at insert time: the
        // cycle is closed by an update once both rows exist.
        $x->peer = $x;
        $y->peer = $x;
        $this->_em->persist($x);
        $this->_em->persist($y);
        $this->_em->flush();

        $x->peer = $y;
        $this->_em->flush();

        $this->_em->remove($x);
        $this->_em->remove($y);

        self::expectException(CycleDetectedException::class);

        $this->_em->flush();
    }

    #[Group('gh5665-scc-cascade-groups')]
    public function testCascadeGroupsWithExternalNullableCycleAreDeleted(): void
    {
        $a      = new GH5665CascadeGroupEntity();
        $b      = new GH5665CascadeGroupEntity();
        $c      = new GH5665CascadeGroupEntity();
        $a->odc = $b;
        $b->odc = $a;
        $a->ref = $c;
        $c->ref = $a;
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

        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        $this->_em->flush();

        // Every entity of the cycle is deleted by its own statement: the
        // CASCADE group {a, b} keeps its representative-first order, and the
        // external ref cycle around the group is broken by one FK-null UPDATE.
        $deletes = $this->queriesStartingWith('DELETE');

        self::assertCount(3, $deletes);
        self::assertCount(1, $this->queriesStartingWith('UPDATE'));

        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665CascadeGroupEntity::class, $aId));
        self::assertNull($this->_em->find(GH5665CascadeGroupEntity::class, $bId));
        self::assertNull($this->_em->find(GH5665CascadeGroupEntity::class, $cId));
    }

    #[Group('gh5665-early-deletions-interplay')]
    public function testEarlyDeletionsFromWholesaleReplacementBreakCycle(): void
    {
        $owner   = new GH5665InterplayOwner();
        $x       = new GH5665InterplayNode();
        $y       = new GH5665InterplayNode();
        $kept    = new GH5665InterplayNode();
        $x->peer = $y;
        $y->peer = $x;
        $owner->addElement($x);
        $owner->addElement($y);
        $owner->addElement($kept);
        $this->_em->persist($owner);
        $this->_em->flush();

        $ownerId = $owner->id;
        $xId     = $x->id;
        $yId     = $y->id;
        $keptId  = $kept->id;

        $this->_em->clear();
        $owner = $this->_em->find(GH5665InterplayOwner::class, $ownerId);
        $kept  = $this->_em->find(GH5665InterplayNode::class, $keptId);

        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        // Wholesale-replacing the orphanRemoval collection carries the third
        // element over and discards the two members of the nullable cycle:
        // their deletions are planned as early deletions (running before the
        // insertions), and the cycle between them has to be broken inside
        // that early deletion step.
        $owner->elements = new ArrayCollection([$kept]);

        $this->_em->flush();

        $updatePositions = $this->queryPositionsStartingWith('UPDATE');
        $deletePositions = $this->queryPositionsStartingWith('DELETE');

        self::assertCount(1, $updatePositions);
        self::assertCount(2, $deletePositions);
        foreach ($deletePositions as $deletePosition) {
            self::assertGreaterThan($updatePositions[0], $deletePosition);
        }

        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665InterplayNode::class, $xId));
        self::assertNull($this->_em->find(GH5665InterplayNode::class, $yId));
        self::assertNotNull($this->_em->find(GH5665InterplayNode::class, $keptId));
        self::assertNotNull($this->_em->find(GH5665InterplayOwner::class, $ownerId));
    }

    #[Group('gh5665-no-cycle-no-fknull')]
    public function testNoCycleProducesNoForeignKeyNullUpdate(): void
    {
        $target           = new GH5665TwoLinkNode();
        $referencing      = new GH5665TwoLinkNode();
        $referencing->ref = $target;
        $this->_em->persist($target);
        $this->_em->persist($referencing);
        $this->_em->flush();

        $referencingId = $referencing->id;
        $targetId      = $target->id;

        $this->_em->clear();
        $referencing = $this->_em->find(GH5665TwoLinkNode::class, $referencingId);

        $queryLog = $this->getQueryLog();
        $queryLog->reset()->enable();

        $this->_em->remove($referencing);
        $this->_em->flush();

        // No cycle: the referencing row is simply deleted first, no foreign
        // key has to be nulled.
        self::assertSame([], $this->queriesStartingWith('UPDATE'));

        $this->_em->clear();

        self::assertNull($this->_em->find(GH5665TwoLinkNode::class, $referencingId));
        self::assertNotNull($this->_em->find(GH5665TwoLinkNode::class, $targetId));
    }

    /** @return list<array{sql: string, params: mixed[]}> */
    private function queriesStartingWith(string $verb): array
    {
        return array_values(array_filter($this->getQueryLog()->queries, static function (array $entry) use ($verb): bool {
            return strpos($entry['sql'], $verb) === 0;
        }));
    }

    /** @return list<int> */
    private function queryPositionsStartingWith(string $verb): array
    {
        $positions = [];

        foreach (array_values($this->getQueryLog()->queries) as $position => $entry) {
            if (strpos($entry['sql'], $verb) === 0) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}

#[ORM\Entity]
class GH5665Item
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    protected int|null $id = null;

    /** @var Collection<int, GH5665Subitem> */
    #[ORM\OneToMany(targetEntity: GH5665Subitem::class, mappedBy: 'item', cascade: ['all'], orphanRemoval: true)]
    protected Collection $items;

    #[ORM\ManyToOne(targetEntity: GH5665Subitem::class)]
    protected GH5665Subitem|null $featuredItem = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function addItem(GH5665Subitem $item): void
    {
        $this->items[] = $item;
        $item->setItem($this);
    }

    public function setFeaturedItem(GH5665Subitem $featuredItem): void
    {
        $this->featuredItem = $featuredItem;
    }
}

#[ORM\Entity]
class GH5665Subitem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    protected int|null $id = null;

    #[ORM\ManyToOne(targetEntity: GH5665Item::class, inversedBy: 'items')]
    protected GH5665Item|null $item = null;

    public function getId(): int|null
    {
        return $this->id;
    }

    public function setItem(GH5665Item|null $item): void
    {
        $this->item = $item;
    }
}

#[ORM\Entity]
class GH5665TwoLinkNode
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public GH5665TwoLinkNode|null $ref = null;
}

#[ORM\Entity]
class GH5665ThreeLinkNode
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public GH5665ThreeLinkNode|null $next = null;
}

#[ORM\Entity]
class GH5665MixedCycleNode
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: false)]
    public GH5665MixedCycleNode|null $hardNext = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public GH5665MixedCycleNode|null $softNext = null;
}

#[ORM\Entity]
class GH5665HardCycleNode
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: false)]
    public GH5665HardCycleNode|null $peer = null;
}

#[ORM\Entity]
class GH5665CascadeGroupEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public GH5665CascadeGroupEntity|null $odc = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    public GH5665CascadeGroupEntity|null $ref = null;
}

#[ORM\Entity]
class GH5665InterplayOwner
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    /** @var Collection<int, GH5665InterplayNode> */
    #[ORM\OneToMany(
        targetEntity: GH5665InterplayNode::class,
        mappedBy: 'owner',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    public Collection $elements;

    public function __construct()
    {
        $this->elements = new ArrayCollection();
    }

    public function addElement(GH5665InterplayNode $element): void
    {
        $this->elements[] = $element;
        $element->owner   = $this;
    }
}

#[ORM\Entity]
class GH5665InterplayNode
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public GH5665InterplayNode|null $peer = null;

    #[ORM\ManyToOne(targetEntity: GH5665InterplayOwner::class, inversedBy: 'elements')]
    public GH5665InterplayOwner|null $owner = null;
}
