silverstripe-logobject
======================

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

- no `$fixed_fields` (`ID`, `ClassName`, `Created` and `LastEdited`)
- no inheritance complexity: just subclass `LogObject` and you are done
- rows cannot be uniquely identified
- no variable-length fields, such as `VARCHAR` or `BLOB`
- existing log entries cannot be deleted
