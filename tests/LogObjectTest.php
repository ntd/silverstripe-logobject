<?php

namespace eNTiDi\LogObject;

use LogicException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\TableBuilder;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBVarchar;

class LogObjectTest extends SapphireTest
{
    const WHEN = '2026-02-02 11:11:11';

    protected $usesDatabase = true;

    /**
     * The ARCHIVE engine is not transactional: log entries cannot be
     * rolled back, so every test gets a brand new temporary database
     * (dropped at teardown). This also avoids the TRUNCATE performed
     * on teardown, which is not supported by the ARCHIVE engine.
     */
    protected $usesTransactions = false;


    protected function setUp(): void
    {
        parent::setUp();
        SampleLog::requireTable();
    }

    public function testExistingDataCanBeRetrieved()
    {
        $when = self::WHEN;
        $message = 'This is a test message';
        $type = 'Info';

        $row = SampleLog::create([
            'When' => $when,
            'Message' => $message,
            'Type' => $type,
        ]);

        // A successful write must return the ID of the new row
        $this->assertGreaterThan(0, $row->write());

        // Check old data can be retrieved in different ways
        $list = SampleLog::get()->filter('When', $when);
        $this->assertGreaterThan(0, $list->count());

        $list = SampleLog::get()->filter('Message', $message);
        $this->assertGreaterThan(0, $list->count());

        $list = SampleLog::get()->filter([
            'When' => $when,
            'Type' => $type,
        ]);
        $this->assertGreaterThan(0, $list->count());

        // A query on a future date should be empty
        $list = SampleLog::get()->filter('When:GreaterThan', $when);
        $this->assertEquals(0, $list->count());
    }

    public function testExistingDataCannotBeDeleted()
    {
        $row = SampleLog::create([
            'When' => self::WHEN,
            'Message' => 'This error cannot be deleted',
            'Type' => 'Error',
        ]);
        $this->assertGreaterThan(0, $row->write());

        $this->expectException(LogicException::class);
        $row->delete();
    }

    public function testTableIsCreatedWithArchiveEngine()
    {
        $options = LogObject::createTableOptions();
        // Actually only `MySQLDatabase` (MariaDB and MySQL) is tested:
        // this could change in the future
        $this->assertArrayHasKey('MySQLDatabase', $options);
        // Check the engine name is in uppercase
        $this->assertStringContainsString('ARCHIVE', $options['MySQLDatabase']);

        $status = DB::query("SHOW TABLE STATUS LIKE 'SampleLog'")->record();
        $this->assertEquals('ARCHIVE', $status['Engine']);

        // Apart from `ID`, no other fixed field must be present
        $columns = array_keys(DB::field_list('SampleLog'));
        sort($columns);
        $this->assertEquals(['ID', 'Message', 'Type', 'When'], $columns);
    }

    public function testRequireTableIsIdempotent()
    {
        // A further requireTable() call must not try to ALTER the
        // table, an operation not supported by the ARCHIVE engine
        SampleLog::requireTable();
        $this->assertTrue(DB::get_schema()->hasTable(SampleLog::tableName()));
    }

    public function testBuildTablesHandlesTestOnlyClasses()
    {
        // Mirrors what DbBuildExtension does on dev/build: requireTable()
        // is called inside an active schemaUpdate() session, where it
        // must also work correctly. As with DataObjects, TestOnly log
        // classes are only built in test mode
        DB::query('DROP TABLE "SampleLog"');
        TableBuilder::singleton()->buildTables(DB::get_schema(), [SampleLog::class], [], true, false);
        $this->assertFalse(DB::get_schema()->hasTable(SampleLog::tableName()));

        TableBuilder::singleton()->buildTables(DB::get_schema(), [SampleLog::class], [], true, true);
        $this->assertTrue(DB::get_schema()->hasTable(SampleLog::tableName()));
    }

    public function testWriteAlwaysAppends()
    {
        $firstId = SampleLog::create([
            'When' => self::WHEN,
            'Message' => 'only one',
            'Type' => 'Debug',
        ])->write();
        $this->assertEquals(1, SampleLog::get()->count());

        // The generated ID is stored on the object and it is preserved
        // when the row is fetched back from the database
        $row = SampleLog::get()->first();
        $this->assertEquals($firstId, $row->ID);

        // Writing a hydrated row appends a new one (ARCHIVE has no
        // UPDATE), with its own newly generated ID
        $secondId = $row->write();
        $this->assertGreaterThan($firstId, $secondId);
        $this->assertEquals($secondId, $row->ID);
        $this->assertEquals(2, SampleLog::get()->count());
    }

    public function testMagicAccessors()
    {
        $row = SampleLog::create([
            'When' => self::WHEN,
            'Message' => 'hello',
            'Type' => 'Warning',
        ]);

        $this->assertEquals('hello', $row->Message);
        $this->assertEquals('hello', $row->getField('Message'));
        $this->assertTrue($row->hasField('Message'));
        $this->assertFalse($row->hasField('Bogus'));
        $this->assertEquals([
            'When' => self::WHEN,
            'Message' => 'hello',
            'Type' => 'Warning',
        ], $row->toMap());

        // Values set on new objects are retrievable after a write
        $row->write();
        $fetched = SampleLog::get()->first();
        $this->assertEquals('hello', $fetched->Message);
        $this->assertEquals(self::WHEN, $fetched->When);
        $this->assertEquals('Warning', $fetched->Type);
    }

    public function testDbObjectAndCasting()
    {
        $row = SampleLog::create([
            'When' => self::WHEN,
            'Message' => 'hello',
            'Type' => 'Warning',
        ]);

        $this->assertInstanceOf(DBDatetime::class, $row->dbObject('When'));
        $this->assertInstanceOf(DBVarchar::class, $row->dbObject('Message'));
        $this->assertEquals(40, $row->dbObject('Message')->getSize());
        $this->assertInstanceOf(DBEnum::class, $row->dbObject('Type'));
        $this->assertNull($row->dbObject('Bogus'));

        // Template-style casting resolves to the declared field type
        $this->assertInstanceOf(DBVarchar::class, $row->obj('Message'));

        $row->setCastedField('Message', 'casted');
        $this->assertEquals('casted', $row->Message);

        // DBField values are unpacked on assignment, as DataObject does
        $row->When = DBField::create_field('Datetime', self::WHEN);
        $this->assertEquals(self::WHEN, $row->When);
        $row->write();
        $this->assertEquals(1, SampleLog::get()->filter('When', self::WHEN)->count());
    }
}
