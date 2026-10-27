<?php

namespace eNTiDi\LogObject;

use Generator;
use InvalidArgumentException;
use LogicException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\Map;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use Traversable;

/**
 * A list of {@link LogObject} records backed by a database query,
 * similar to a stripped down DataList.
 *
 * The list is immutable: the filter(), sort() and limit() methods
 * return a new list instead of modifying the current one, so they
 * can be safely chained:
 *
 * <code>
 * $errors = SampleLog::get()
 *     ->filter('Type', 'Error')
 *     ->sort('When', 'DESC')
 *     ->limit(10);
 * </code>
 *
 * Supported filter modifiers are ExactMatch (the default), NotEqual,
 * PartialMatch, StartsWith, EndsWith, GreaterThan, GreaterThanOrEqual,
 * LessThan, LessThanOrEqual, In, NotIn, Null and NotNull. Field values
 * are always bound as query parameters.
 *
 * @implements SS_List<LogObject>
 */
class LogList implements SS_List
{
    use Injectable;

    /**
     * The class of the listed records: a LogObject subclass.
     */
    private string $dataClass;

    /**
     * The query used to fetch the records from the database.
     */
    private SQLSelect $query;

    public function __construct(string $dataClass)
    {
        if (!is_subclass_of($dataClass, LogObject::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s is not a %s subclass',
                $dataClass,
                LogObject::class
            ));
        }

        $this->dataClass = $dataClass;
        $this->query = SQLSelect::create()
            ->setSelect('*')
            ->setFrom(sprintf('"%s"', $dataClass::tableName()));
    }

    /**
     * Make sure cloned lists get their own query.
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * Get the class of the listed records.
     */
    public function getDataClass(): string
    {
        return $this->dataClass;
    }

    /**
     * Get the query backing this list, e.g. to inspect or execute it.
     */
    public function getQuery(): SQLSelect
    {
        return $this->query;
    }

    public function count(): int
    {
        return (int) $this->query->count();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function getIterator(): Traversable
    {
        // Use a generator instead of materializing all the records:
        // log tables can easily hold a huge number of rows
        foreach ($this->query->execute() as $row) {
            yield new $this->dataClass($row);
        }
    }

    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    public function toNestedArray(): array
    {
        return array_map(
            static fn (LogObject $record) => $record->toMap(),
            $this->toArray()
        );
    }

    public function first(): mixed
    {
        foreach ($this->getIterator() as $record) {
            return $record;
        }

        return null;
    }

    public function last(): mixed
    {
        $records = $this->toArray();

        return $records ? end($records) : null;
    }

    public function map(string $keyfield = 'ID', string $titlefield = 'Title'): Map
    {
        return new Map($this, $keyfield, $titlefield);
    }

    public function find(string $key, mixed $value): mixed
    {
        return $this->filter($key, $value)->first();
    }

    public function column(string $colName = 'ID'): array
    {
        $query = clone $this->query;
        $query->setSelect($this->quotedColumn($colName));

        return $query->execute()->column();
    }

    public function columnUnique(string $colName = 'ID'): array
    {
        return array_unique($this->column($colName));
    }

    public function each(callable $callback): SS_List
    {
        foreach ($this->getIterator() as $record) {
            $callback($record, $this);
        }

        return $this;
    }

    public function canFilterBy(string $by): bool
    {
        return $this->resolveQuotedColumn($by) !== null;
    }

    public function canSortBy(string $by): bool
    {
        return $this->resolveQuotedColumn($by) !== null;
    }

    public function filter(mixed ...$args): SS_List
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), false, false);
    }

    public function filterAny(mixed ...$args): SS_List
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), false, true);
    }

    public function exclude(mixed ...$args): SS_List
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), true, false);
    }

    public function excludeAny(mixed ...$args): SS_List
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), true, true);
    }

    public function filterByCallback(callable $callback): SS_List
    {
        $results = array_filter($this->toArray(), fn ($record) => $callback($record, $this));

        return ArrayList::create(array_values($results));
    }

    public function byID(mixed $id): mixed
    {
        throw new LogicException(sprintf(
            'Rows of %s cannot be uniquely identified: byID() is not available',
            $this->dataClass
        ));
    }

    public function byIDs(array $ids): SS_List
    {
        throw new LogicException(sprintf(
            'Rows of %s cannot be uniquely identified: byIDs() is not available',
            $this->dataClass
        ));
    }

    public function sort(mixed ...$args): SS_List
    {
        $list = clone $this;
        $list->query->setOrderBy($this->normaliseSortArgs($args));

        return $list;
    }

    public function reverse(): SS_List
    {
        $list = clone $this;
        $list->query->reverseOrderBy();

        return $list;
    }

    public function limit(?int $length, int $offset = 0): SS_List
    {
        $list = clone $this;
        $list->query->setLimit($length, $offset);

        return $list;
    }

    /**
     * Log tables are append-only: new records can only be created
     * with LogObject::write(), so they cannot be added to a list.
     */
    public function add(mixed $item): void
    {
        throw new LogicException(sprintf(
            '%s is backed by a query: create new records with %s::create() and write() instead',
            static::class,
            $this->dataClass
        ));
    }

    /**
     * Log entries cannot be removed: the ARCHIVE engine does not
     * support the DELETE statement.
     */
    public function remove(mixed $item)
    {
        throw new LogicException('LogList items cannot be removed: log tables are append-only.');
    }

    /**
     * Log lists are generated by a query, so they can only be
     * accessed by offset in a read-only way.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('LogList is read-only: items cannot be modified.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('LogList is read-only: items cannot be removed.');
    }

    /**
     * Normalise the arguments accepted by filter(), exclude() and
     * their Any() variants to a field => value map.
     *
     * @param array $args
     * @param string $function Name of the calling method, for error messages
     * @return array
     */
    private function normaliseFilterArgs(array $args, string $function): array
    {
        if (count($args) === 1) {
            $filters = array_values($args)[0];
            if (!is_array($filters)) {
                throw new InvalidArgumentException(sprintf(
                    '%s() expects an array of filters, or a field name and a value',
                    $function
                ));
            }

            return $filters;
        }

        if (count($args) === 2 && is_string($args[0])) {
            return [$args[0] => $args[1]];
        }

        throw new InvalidArgumentException(sprintf(
            '%s() expects an array of filters, or a field name and a value',
            $function
        ));
    }

    /**
     * Add the given filters to a copy of this list, combining them
     * with AND (the default) or OR (the $any variants) and negating
     * them when required.
     */
    private function addFilters(array $filters, bool $negate, bool $any): SS_List
    {
        $predicates = [];
        foreach ($filters as $name => $value) {
            [$sql, $parameters] = $this->buildPredicate((string) $name, $value);
            if ($negate) {
                $sql = sprintf('NOT (%s)', $sql);
            }
            $predicates[$sql] = $parameters;
        }

        if (!$predicates) {
            return $this;
        }

        $list = clone $this;
        if ($any) {
            $list->query->addWhereAny($predicates);
        } else {
            $list->query->addWhere($predicates);
        }

        return $list;
    }

    /**
     * Convert a 'Field:Modifier' => value pair to a parameterised SQL
     * predicate and its bound parameters.
     *
     * @return array Two elements: the SQL predicate and its parameters
     */
    private function buildPredicate(string $name, mixed $value): array
    {
        $parts = explode(':', $name);
        $column = $this->quotedColumn(trim(array_shift($parts)));

        $negate = false;
        $modifier = '';
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if ($part === 'not') {
                $negate = !$negate;
                continue;
            }
            if ($modifier !== '') {
                throw new InvalidArgumentException(sprintf(
                    'Only one filter modifier is supported, got "%s"',
                    $name
                ));
            }
            $modifier = $part;
        }

        [$sql, $parameters] = match ($modifier) {
            '', 'exactmatch' => $this->exactPredicate($column, $value),
            'notequal' => $this->exactPredicate($column, $value, true),
            'partialmatch' => $this->likePredicate($column, $value, '%', '%'),
            'startswith' => $this->likePredicate($column, $value, '', '%'),
            'endswith' => $this->likePredicate($column, $value, '%', ''),
            'greaterthan' => $this->comparisonPredicate($column, $value, '>'),
            'greaterthanorequal' => $this->comparisonPredicate($column, $value, '>='),
            'lessthan' => $this->comparisonPredicate($column, $value, '<'),
            'lessthanorequal' => $this->comparisonPredicate($column, $value, '<='),
            'in' => $this->inPredicate($column, $value),
            'notin' => $this->inPredicate($column, $value, true),
            'null' => [$column . ' IS NULL', []],
            'notnull' => [$column . ' IS NOT NULL', []],
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported filter modifier "%s" for field "%s"',
                $modifier,
                $name
            )),
        };

        if ($negate) {
            $sql = sprintf('NOT (%s)', $sql);
        }

        return [$sql, $parameters];
    }

    /**
     * Build an exact match predicate, either = or <> (or IN/NOT IN
     * when an array of values is given).
     *
     * @return array Two elements: the SQL predicate and its parameters
     */
    private function exactPredicate(string $column, mixed $value, bool $negate = false): array
    {
        $operator = $negate ? '<>' : '=';
        if ($value === null) {
            return [$column . ' IS ' . ($negate ? 'NOT ' : '') . 'NULL', []];
        }
        if (is_array($value)) {
            return $this->inPredicate($column, $value, $negate);
        }

        return [sprintf('%s %s ?', $column, $operator), [$value]];
    }

    /**
     * Build a LIKE predicate, prepending and appending wildcards to
     * the (scalar) value as requested.
     *
     * @return array Two elements: the SQL predicate and its parameters
     */
    private function likePredicate(string $column, mixed $value, string $prefix, string $suffix): array
    {
        if (is_array($value)) {
            throw new InvalidArgumentException('Array values cannot be used with LIKE filters');
        }

        return [sprintf('%s LIKE ?', $column), [$prefix . $value . $suffix]];
    }

    /**
     * Build a comparison predicate (> >= < <=).
     *
     * @return array Two elements: the SQL predicate and its parameters
     */
    private function comparisonPredicate(string $column, mixed $value, string $operator): array
    {
        if (is_array($value)) {
            throw new InvalidArgumentException('Array values cannot be used with comparison filters');
        }

        return [sprintf('%s %s ?', $column, $operator), [$value]];
    }

    /**
     * Build an IN() predicate for an array of values. An empty array
     * matches nothing (or everything, when negated).
     *
     * @return array Two elements: the SQL predicate and its parameters
     */
    private function inPredicate(string $column, mixed $value, bool $negate = false): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $value = array_values($value);
        if (!$value) {
            return [$negate ? '1 = 1' : '1 = 0', []];
        }

        $sql = sprintf('%s IN (%s)', $column, DB::placeholders($value));

        return [$negate ? sprintf('NOT (%s)', $sql) : $sql, $value];
    }

    /**
     * Normalise the arguments accepted by sort() to a "Column ASC|DESC"
     * SQL fragment.
     */
    private function normaliseSortArgs(array $args): string
    {
        if (count($args) === 1 && is_array($argument = array_values($args)[0])) {
            $clauses = [];
            foreach ($argument as $field => $direction) {
                $clauses[] = $this->sortClause((string) $field, $direction);
            }

            return implode(', ', $clauses);
        }

        // Support both sort('Field') and sort('Field DESC')
        if (count($args) === 1 && is_string($argument = array_values($args)[0])) {
            if (preg_match('/^(.*\S)\s+(ASC|DESC)$/i', $argument, $matches)) {
                return $this->sortClause($matches[1], $matches[2]);
            }

            return $this->sortClause($argument, 'ASC');
        }

        if (count($args) === 2 && is_string($args[0])) {
            return $this->sortClause($args[0], $args[1]);
        }

        throw new InvalidArgumentException(
            'sort() expects a field name and an optional direction, or an array of them'
        );
    }

    /**
     * Build a single sort clause, validating the field name and the
     * direction against SQL injection.
     */
    private function sortClause(string $field, mixed $direction): string
    {
        $direction = strtoupper((string) $direction);
        if ($direction === '') {
            $direction = 'ASC';
        }
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid sort direction "%s": use ASC or DESC',
                $direction
            ));
        }

        return $this->quotedColumn($field) . ' ' . $direction;
    }

    /**
     * Get the quoted column name for a field declared in $db, or null
     * if the field is not available.
     */
    private function resolveQuotedColumn(string $field): ?string
    {
        foreach ($this->dataClass::dbFields() as $name => $spec) {
            if (strcasecmp($name, $field) === 0) {
                return sprintf('"%s"', $name);
            }
        }

        return null;
    }

    /**
     * Same as resolveQuotedColumn(), but throwing an exception on
     * unknown fields: this also protects the generated SQL.
     */
    private function quotedColumn(string $field): string
    {
        $column = $this->resolveQuotedColumn($field);
        if ($column === null) {
            throw new InvalidArgumentException(sprintf(
                'Cannot use "%s": not a database field of %s',
                $field,
                $this->dataClass
            ));
        }

        return $column;
    }
}
