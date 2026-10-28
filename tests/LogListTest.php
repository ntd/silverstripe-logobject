<?php

namespace eNTiDi\LogObject;

use InvalidArgumentException;
use SilverStripe\Dev\SapphireTest;

class LogListTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected $usesTransactions = false;

    protected function setUp(): void
    {
        parent::setUp();
        SampleLog::requireTable();

        $records = [
            ['alpha', 'Debug', '2026-01-01 10:00:00'],
            ['beta', 'Info', '2026-01-02 10:00:00'],
            ['gamma', 'Error', '2026-01-03 10:00:00'],
        ];
        foreach ($records as [$message, $type, $when]) {
            SampleLog::create([
                'When' => $when,
                'Message' => $message,
                'Type' => $type,
            ])->write();
        }
    }

    public function testCountExistsAndIteration()
    {
        $list = SampleLog::get();
        $this->assertEquals(3, $list->count());
        $this->assertTrue($list->exists());

        $rows = $list->sort('When')->toArray();
        $this->assertCount(3, $rows);
        $this->assertInstanceOf(SampleLog::class, $rows[0]);
        $this->assertEquals('alpha', $rows[0]->Message);
        $this->assertEquals(['alpha', 'beta', 'gamma'], $list->sort('When')->column('Message'));

        $empty = SampleLog::get()->filter('Message', 'missing');
        $this->assertEquals(0, $empty->count());
        $this->assertFalse($empty->exists());
    }

    public function testFilters()
    {
        $list = SampleLog::get();

        $this->assertEquals(1, $list->filter('Message', 'alpha')->count());
        $this->assertEquals(2, $list->filter('Message', ['alpha', 'beta'])->count());
        $this->assertEquals(3, $list->filter('Message:In', ['alpha', 'beta', 'gamma'])->count());
        $this->assertEquals(1, $list->filter('Message:In', ['alpha', 'beta'])->filter('Type:NotIn', ['Debug'])->count());
        $this->assertEquals(1, $list->filter('Message:NotIn', ['alpha', 'gamma'])->count());
        $this->assertEquals(1, $list->filter('Message:NotEqual', 'alpha')->filter('Message:NotEqual', 'beta')->count());

        $this->assertEquals(2, $list->filter('When:GreaterThan', '2026-01-01 10:00:00')->count());
        $this->assertEquals(3, $list->filter('When:GreaterThanOrEqual', '2026-01-01 10:00:00')->count());
        $this->assertEquals(1, $list->filter('When:LessThan', '2026-01-02 10:00:00')->count());
        $this->assertEquals(2, $list->filter('When:LessThanOrEqual', '2026-01-02 10:00:00')->count());

        $this->assertEquals(1, $list->filter('Message:PartialMatch', 'amm')->count());
        $this->assertEquals(1, $list->filter('Message:StartsWith', 'be')->count());
        $this->assertEquals(1, $list->filter('Message:EndsWith', 'ta')->count());
        $this->assertEquals(0, $list->filter('Message:PartialMatch', 'zzz')->count());

        // The `not` modifier negates the comparison
        $this->assertEquals(2, $list->filter('Message:not', 'alpha')->count());
    }

    public function testNullFilters()
    {
        SampleLog::create([
            'When' => '2026-01-04 10:00:00',
            'Message' => null,
            'Type' => 'Info',
        ])->write();

        $this->assertEquals(1, SampleLog::get()->filter('Message', null)->count());
        $this->assertEquals(1, SampleLog::get()->filter('Message:Null')->count());
        $this->assertEquals(3, SampleLog::get()->filter('Message:NotNull')->count());
    }

    public function testFilterAnySemantics()
    {
        $list = SampleLog::get();

        // Filters of a single filterAny() call are ORed together
        $this->assertEquals(2, $list->filterAny(['Message' => 'alpha', 'Type' => 'Error'])->count());
        $this->assertEquals(2, $list->filterAny(['Message' => ['alpha', 'gamma']])->count());

        // As in DataList, separate filterAny() calls are ANDed together
        $this->assertEquals(0, $list->filterAny('Message', 'alpha')->filterAny('Message', 'gamma')->count());
    }

    public function testExclude()
    {
        $list = SampleLog::get();

        $this->assertEquals(2, $list->exclude('Type', 'Debug')->count());
        $this->assertEquals(1, $list->exclude(['Type' => ['Debug', 'Error']])->count());
        $this->assertEquals(1, $list->exclude('Message', ['alpha', 'beta'])->count());

        // The arguments of a single excludeAny() call are ORed after
        // being negated, while separate calls are ANDed
        $this->assertEquals(2, $list->excludeAny(['Message' => ['alpha', 'beta'], 'Type' => 'Debug'])->count());
        $this->assertEquals(0, $list->excludeAny('Message', 'alpha')->excludeAny('Message', 'beta')->excludeAny('Message', 'gamma')->count());
    }

    public function testSortAndLimit()
    {
        $list = SampleLog::get();

        $this->assertEquals(['gamma', 'beta', 'alpha'], $list->sort('When', 'DESC')->column('Message'));
        $this->assertEquals(['alpha', 'beta', 'gamma'], $list->sort('When ASC')->column('Message'));
        $this->assertEquals(['beta', 'gamma'], $list->sort('When')->limit(2, 1)->column('Message'));
        $this->assertEquals([], $list->limit(0)->column('Message'));

        $this->assertEquals('alpha', $list->sort('When')->first()->Message);
        $this->assertNull($list->filter('Message', 'missing')->first());

        // Enum columns are ordered by their definition index
        // (Debug, Info, Warning, Error), not alphabetically
        $this->assertEquals(['alpha', 'beta', 'gamma'], $list->sort(['Type' => 'ASC'])->column('Message'));
        $this->assertEquals(['gamma', 'beta', 'alpha'], $list->sort(['Type' => 'DESC', 'When' => 'DESC'])->column('Message'));
    }

    public function testListsAreImmutable()
    {
        $base = SampleLog::get();

        $filtered = $base->filter('Type', 'Debug');
        $this->assertEquals(1, $filtered->count());
        $this->assertEquals(3, $base->count());

        $limited = $base->limit(1);
        $this->assertEquals(1, $limited->count());
        $this->assertEquals(3, $base->count());

        $sorted = $base->sort('When', 'DESC');
        $this->assertEquals(['gamma', 'beta', 'alpha'], $sorted->column('Message'));
        $this->assertEquals(['alpha', 'beta', 'gamma'], $base->sort('When')->column('Message'));
    }

    public function testFilterRejectsUnknownFields()
    {
        $this->expectException(InvalidArgumentException::class);
        SampleLog::get()->filter('Bogus', 'x');
    }

    public function testFilterRejectsUnknownModifiers()
    {
        $this->expectException(InvalidArgumentException::class);
        SampleLog::get()->filter('Message:Bogus', 'x');
    }

    public function testFilterRejectsArrayValuesOnComparisons()
    {
        $this->expectException(InvalidArgumentException::class);
        SampleLog::get()->filter('When:GreaterThan', ['a', 'b']);
    }

    public function testSortRejectsUnknownFields()
    {
        $this->expectException(InvalidArgumentException::class);
        SampleLog::get()->sort('Bogus');
    }

    public function testSortRejectsInvalidDirections()
    {
        $this->expectException(InvalidArgumentException::class);
        SampleLog::get()->sort('Message', 'BOGUS');
    }
}
