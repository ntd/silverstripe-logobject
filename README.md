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

- cannot delete or update existing rows
- no `$fixed_fields` apart from `ID`
- no indexes
- no relations or foreign keys support
- no inheritance complexity: subclasses do not merge fields with the
  parent classes
