# silverstripe-logobject

Having immutable tables can be useful in many situations, e.g. with logs
or GDPR specific requirements. MySQL and MariaDB provide the
[ARCHIVE engine](https://mariadb.com/docs/server/server-usage/storage-engines/archive)
which seems well suited for this purpose.

Unfortunately, Silverstripe's `DataObject` class cannot be used directly
with this engine. Silverstripe relies on several `$fixed_fields` and
database indexes that are tightly coupled to its implementation and
cannot easily be disabled or customized.

This module provides a dedicated `DataObjectInterface` for log records,
designed to work with the ARCHIVE storage engine. It enforces
restrictions that make sense for append-only logs such as:

- cannot delete or update existing rows
- no `$fixed_fields` apart from `ID`
- no indexes
- no relations or foreign keys support
- no inheritance complexity: subclasses do not merge fields with the
  parent classes

## License

See [License](LICENSE.md).

This module template defaults to using the "BSD-3-Clause" license.
The BSD-3 license is one of the most permissive open-source license and
is used by most Silverstripe CMS module.

## Installation

```sh
composer require entidi/silverstripe-logobject
```

## Documentation

Just use `LogObject` instead of `DataObject`, e.g.:

```php
use eNTiDi\LogObject\LogObject;
use SilverStripe\ORM\FieldType\DBDatetime;

class MyLog extends LogObject
{
    private static $table_name = 'MyLog';

    // The `ID` primary key is (always) automatically created
    private static $db = [
        'Created' => 'Datetime',
        'Facility' => "Enum('Auth,Cron,FTP,Mail')",
        'Level' => "Enum('Debug,Info,Warning,Error')",
        'Message' => 'Varchar(200)',
        'PID' => 'Int',
    ];
}

// Save a new row
$log = MyLog::create([
    'Created' => DBDatetime::now(),
    'Level' => 'Debug',
]);
$log->setField('Facility', 'FTP');
$log->Message = 'Some message here...';
$log->PID = 123;
$id = $log->write();
```
