<?php

namespace eNTiDi\LogObject;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Queries\SQLInsert;

/**
 * A minimal, append-only record class, similar to a stripped down
 * {@link \SilverStripe\ORM\DataObject} but designed for tables using
 * the ARCHIVE storage engine of MySQL/MariaDB.
 *
 * Differently from DataObject, LogObject:
 * - only the `ID` field is hardcoded (primary key autoincrement);
 * - no `$fixed_fields` (`ClassName`, `Created` and `LastEdited`);
 * - no other indexes (this is a requirement of the ARCHIVE engine);
 * - no relations or foreign keys support;
 * - no inheritance complexity (subclasses do not merge fields with the
 *   parent classes);
 * - cannot delete or update existing rows;
 * - plenty of other facilities dropped: many before/after callbacks,
 *   changed states, validation, l10n and more.
 *
 * Usage example:
 * ```
 * class MyLog extends LogObject
 * {
 *     private static string $table_name = 'MyLogTable';
 *
 *     private static array $db = [
 *         'When' => 'Datetime',
 *         'Message' => 'Varchar(40)',
 *         'Type' => 'Enum("Debug,Info,Warning,Error")',
 *     ];
 * }
 * ```
 */
class LogObject extends ModelData implements DataObjectInterface
{
    /**
     * Name of the table backing this class. When empty, it is derived
     * from the short class name.
     *
     * @config
     */
    private static string $table_name = '';

    /**
     * Map of field names to field type specifications, in the same
     * format used by DataObject::$db.
     *
     * @config
     */
    private static array $db = [];

    /**
     * Extra clauses appended to the generated CREATE TABLE statement,
     * keyed by database class. This is where the storage engine is
     * selected, e.g. ['MySQLDatabase' => 'ENGINE=Archive'].
     *
     * @config
     */
    private static array $create_table_options = [];

    /**
     * Values of the fields declared in $db, indexed by field name.
     */
    protected array $record = [];

    /**
     * Create a new LogObject.
     *
     * @param array $record Initial record content
     */
    public function __construct(array $record = [])
    {
        parent::__construct();
        $this->update($record);
    }

    /**
     * Get the name of the table backing this class.
     *
     * @return string
     */
    public static function tableName(): string
    {
        $table = static::config()->get('table_name');
        if ($table) {
            return (string) $table;
        }

        // Fall back to the unqualified class name, as DataObject does
        $reflection = new ReflectionClass(static::class);
        return $reflection->getShortName();
    }

    /**
     * Get the field specifications declared in $db.
     *
     * @return array Map of field name to field type specification
     */
    public static function dbFields(): array
    {
        return (array) static::config()->get('db');
    }

    /**
     * Get the specification of a single field.
     *
     * @param string $field The field name
     * @return string The field type specification, or an empty string
     *                if the field is not declared in $db
     */
    public static function dbSpec(string $field): string
    {
        $fields = static::dbFields();
        return $fields[$field] ?? '';
    }

    /**
     * Return the list of all objects of this class, similar to
     * DataObject::get() but with a much smaller feature set: see
     * {@link LogList} for what can be done with the result.
     */
    public static function get(): LogList
    {
        return LogList::create(static::class);
    }

    /**
     * Check the database schema and update it as necessary.
     *
     * The `DB::require_table()` calls used below only *record* the
     * requested changes, deferring their execution to the end of a
     * `schemaUpdate()` session: everything must be wrapped to
     * actually have the schema updated.
     */
    public static function requireTable()
    {
        DB::get_schema()->schemaUpdate(function () {
            $table = static::tableName();
            $fields = static::dbFields();
            if ($fields) {
                DB::require_table(
                    $table,
                    $fields,
                    null,
                    true,
                    static::config()->get('create_table_options'),
                    []
                );
            } else {
                DB::dont_require_table($table);
            }
        });
    }

    /**
     * Append this object to its table.
     *
     * As the ARCHIVE engine only supports INSERT and SELECT, writing
     * always appends a new row, even when called on objects hydrated
     * from the database: log rows cannot be updated.
     *
     * @return int The ID of the newly created row
     */
    public function write(): int
    {
        $this->extend('onBeforeWrite');

        $data = [];
        foreach (static::dbFields() as $name => $spec) {
            $obj = DBField::create_field($spec, $this->record[$name] ?? null, $name);
            $data['"' . $name . '"'] = $obj->exists()
                ? $obj->prepValueForDB($obj->RAW())
                : $obj->nullValue();
        }

        $table = static::tableName();
        $insert = SQLInsert::create('"' . $table . '"', $data);
        $insert->execute();

        // The ID column is auto-generated: keep track of it, so the
        // object gets linked to its newly created row
        $id = (int) DB::get_generated_id($table);
        $this->record['ID'] = $id;

        $this->extend('onAfterWrite');
        return $id;
    }

    /**
     * Log entries cannot be deleted.
     * The ARCHIVE engine does not support the DELETE statement.
     *
     * @throws Exception
     */
    public function delete()
    {
        throw new LogicException('Existing LogObject entries cannot be deleted.');
    }

    /**
     * Set the fields of this object from an associative array.
     * Similar to DataObject::update().
     *
     * @param array $data A map of field name to data values to update
     * @return static $this
     */
    public function update(array $record): static
    {
        foreach ($record as $key => $value) {
            $this->setField($key, $value);
        }
        return $this;
    }

    /**
     * Convert this object to a map.
     * Similar to DataObject::toMap().
     *
     * @return array The data as a map
     */
    public function toMap(): array
    {
        return $this->record;
    }

    /**
     * Get the DBField object representing a field of this object.
     *
     * @param string $field The field name
     * @return DBField|null `null` if the field doesn't exist
     */
    public function dbObject(string $field): ?DBField
    {
        $spec = static::dbSpec($field);
        return $spec
            ? DBField::create_field($spec, $this->record[$field] ?? null, $field)
            : null;
    }

    /**
     * Set the value of the field, using a casting object.
     * Copy of DataObject::setCastedField().
     *
     * @param string $fieldName Name of the field
     * @param mixed $value New field value
     * @return $this
     */
    public function setCastedField($field, $value)
    {
        if (!$field) {
            throw new InvalidArgumentException('LogObject::setCastedField() called without a field name');
        }
        $obj = $this->dbObject($field);
        if ($obj) {
            $obj->setValue($value);
            $obj->saveInto($this);
        } else {
            $this->setField($field, $value);
        }
        return $this;
    }

    /**
     * Check if a field exists.
     *
     * @param string $field The field name
     * @return bool `true` if the field `$field` exists
     */
    public function hasField(string $field): bool
    {
        return array_key_exists($field, $this->record ?? [])
            || !empty(static::dbSpec($field))
            || parent::hasField($field);
    }

    /**
     * Gets the value of a field.
     *
     * @param string $field The field name
     * @return mixed
     */
    public function getField(string $field): mixed
    {
        if (isset($this->record[$field])) {
            return $this->record[$field];
        }

        if (static::dbSpec($field)) {
            // Declared but still unset field
            return null;
        }

        return parent::getField($field);
    }

    /**
     * Set the value of the field.
     *
     * Unexistent fields are simply ignored.
     *
     * @return $this
     */
    public function setField(string $field, mixed $value): static
    {
        $fields = static::dbFields();
        if (array_key_exists($field, $fields) || $field === 'ID') {
            $this->objCacheClear();
            // Unpack DBField values, as DataObject does
            if ($value instanceof DBField) {
                $value = $value->getValue();
            }
            $this->record[$field] = $value;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function castingHelper(string $field): ?string
    {
        $fields = static::dbFields();
        return $fields[$field] ?? parent::castingHelper($field);
    }
}
