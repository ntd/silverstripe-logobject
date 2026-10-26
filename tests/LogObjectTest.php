<?php

namespace eNTiDi\LogObject;

use LogicException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class LogObjectTest extends SapphireTest
{
    public function testOldDataCanBeRetrieved()
    {
        $now = DBDatetime::now()->getValue();
        $message = 'This is a test message';
        $type = 'Info';

        $row = SampleLog::create([
            'When' => $now,
            'Message' => $message,
            'Type' => $type,
        ]);

        // A successful write must return `true`
        $this->assertTrue($row->write());

        // Check old data can be retrieved in different ways
        $list = SampleLog::get()->filter('When', $now);
        $this->assertGreaterThan(0, $list->count());

        $list = SampleLog::get()->filter('Message', $message);
        $this->assertGreaterThan(0, $list->count());

        $list = SampleLog::get()->filter([
            'When' => $now,
            'Type' => $type,
        ]);
        $this->assertGreaterThan(0, $list->count());

        // A query on a future date should be empty
        $list = SampleLog::get()->filter('When:GreaterThan', $now);
        $this->assertEquals(0, $list->count());
    }

    public function testOldDataCannotBeDeleted()
    {
        $row = SampleLog::create([
            'When' => DBDatetime::now()->getValue(),
            'Message' => 'Check if this error can be deleted',
            'Type' => 'Error',
        ]);
        $this->assertTrue($row->write());

        $this->expectException(LogicException::class);
        $row->delete();
    }
}
