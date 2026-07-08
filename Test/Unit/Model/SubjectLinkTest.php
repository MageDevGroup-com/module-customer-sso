<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Test\Unit\Model;

use MageDevGroup\CustomerSso\Model\SubjectLink;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SubjectLinkTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var SubjectLink */
    private SubjectLink $subjectLink;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($t) => 'prefix_' . $t);

        $this->subjectLink = new SubjectLink($resource);
    }

    /**
     * A Select stub whose fluent builders return itself.
     *
     * @return Select&\PHPUnit\Framework\MockObject\Stub
     */
    private function selectStub()
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    /**
     * A Select mock asserting the table/column read and the where predicate.
     *
     * @param string|string[] $column
     * @param string|int $whereValue
     * @return Select&MockObject
     */
    private function selectExpecting($column, string $whereClause, $whereValue)
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())
            ->method('from')
            ->with('prefix_' . SubjectLink::TABLE, $column)
            ->willReturnSelf();
        $select->expects(self::once())
            ->method('where')
            ->with($whereClause, $whereValue)
            ->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    public function testFindCustomerIdBySubjectReturnsId(): void
    {
        // Lookup is scoped by both provider code and subject.
        $where = [];
        $select = $this->createMock(Select::class);
        $select->expects(self::once())
            ->method('from')
            ->with('prefix_' . SubjectLink::TABLE, 'customer_id')
            ->willReturnSelf();
        $select->expects(self::exactly(2))
            ->method('where')
            ->willReturnCallback(function ($clause, $value) use ($select, &$where) {
                $where[] = [$clause, $value];
                return $select;
            });
        $select->method('limit')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())->method('fetchOne')->with($select)->willReturn('42');

        self::assertSame(42, $this->subjectLink->findCustomerIdBySubject('okta', 'sub-1'));
        self::assertSame([['provider_code = ?', 'okta'], ['subject_id = ?', 'sub-1']], $where);
    }

    public function testFindCustomerIdBySubjectReturnsNullWhenAbsent(): void
    {
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->expects(self::once())->method('fetchOne')->willReturn(false);

        self::assertNull($this->subjectLink->findCustomerIdBySubject('okta', 'sub-x'));
    }

    public function testFindCustomerIdBySubjectShortCircuitsOnEmptySubject(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertNull($this->subjectLink->findCustomerIdBySubject('okta', ''));
    }

    public function testFindCustomerIdBySubjectShortCircuitsOnEmptyProvider(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertNull($this->subjectLink->findCustomerIdBySubject('', 'sub-1'));
    }

    public function testGetIdentityByCustomerIdReturnsProviderAndSubject(): void
    {
        $select = $this->selectExpecting(['provider_code', 'subject_id'], 'customer_id = ?', 7);
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())
            ->method('fetchRow')
            ->with($select)
            ->willReturn(['provider_code' => 'okta', 'subject_id' => 'sub-9']);

        self::assertSame(
            ['provider_code' => 'okta', 'subject_id' => 'sub-9'],
            $this->subjectLink->getIdentityByCustomerId(7)
        );
    }

    public function testGetIdentityByCustomerIdReturnsNullWhenAbsent(): void
    {
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->expects(self::once())->method('fetchRow')->willReturn(false);

        self::assertNull($this->subjectLink->getIdentityByCustomerId(7));
    }

    public function testGetIdentityByCustomerIdShortCircuitsOnNonPositiveId(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertNull($this->subjectLink->getIdentityByCustomerId(0));
    }

    public function testLinkInsertsOnDuplicate(): void
    {
        $this->connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'prefix_' . SubjectLink::TABLE,
                ['customer_id' => 5, 'provider_code' => 'okta', 'subject_id' => 'sub-5'],
                ['provider_code', 'subject_id']
            );

        $this->subjectLink->link(5, 'okta', 'sub-5');
    }

    public function testLinkShortCircuitsOnInvalidInput(): void
    {
        $this->connection->expects(self::never())->method('insertOnDuplicate');

        $this->subjectLink->link(0, 'okta', 'sub');
        $this->subjectLink->link(5, 'okta', '');
        $this->subjectLink->link(5, '', 'sub');
    }
}
