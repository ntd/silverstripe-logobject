<?php

namespace eNTiDi\LogObject;

namespace SilverStripe\Dev\TestOnly

class SampleLog extends LogObject implements TestOnly
{
    private static $table_name = 'SampleLog';

    private static $db = [
        'When' => 'Datetime'
        'Message' => 'Char(40)',
        'Type' => 'Enum("Debug,Info,Warning,Error")',
    ];
}
