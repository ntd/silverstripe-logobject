<?php

namespace eNTiDi\LogObject;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;

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
 * Filter fields can specify a modifier, such as in
 * `filter('When:GreaterThan', $when)`. The supported modifiers are
 * ExactMatch (the default), NotEqual, PartialMatch, StartsWith,
 * EndsWith, GreaterThan, GreaterThanOrEqual, LessThan,
 * LessThanOrEqual, In, NotIn, Null and NotNull. Filter values are
 * always bound as query parameters.
 *
 * As in DataList, multiple filter() calls (and multiple filterAny()
 * calls) are combined with AND, while the arguments of a single
 * filterAny() call are combined with OR.
 */
class LogList implements \Countable, \IteratorAggregate
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

    public function count(): int
    {
        return (int) $this->query->count();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Iterate the records without loading all of them in memory:
     * log tables can easily hold a huge number of rows.
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->query->execute() as $row) {
            yield new $this->dataClass($row);
        }
    }

    /**
     * Return the records as a plain array.
     *
     * @return array<int, LogObject>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * Return the first record of the list, or null if there is none.
     */
    public function first(): ?LogObject
    {
        foreach ($this->getIterator() as $record) {
            return $record;
        }

        return null;
    }

    /**
     * Return the value of a single column for all the records,
     * preserving the filters, the sort and the limit of the list.
     */
    public function column(string $field): array
    {
        $query = clone $this->query;
        $query->setSelect($this->quotedColumn($field));

        return $query->execute()->column();
    }

    public function filter(mixed ...$args): static
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), false, false);
    }

    public function exclude(mixed ...$args): static
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), true, false);
    }

    public function filterAny(mixed ...$args): static
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), false, true);
    }

    public function excludeAny(mixed ...$args): static
    {
        return $this->addFilters($this->normaliseFilterArgs($args, __FUNCTION__), true, true);
    }

    /**
     * Return a new list, sorted by one or more fields.
     *
     * @example $list->sort('When')
     * @example $list->sort('When', 'DESC')
     * @example $list->sort(['When' => 'DESC', 'Type' => 'ASC'])
     */
    public function sort(mixed ...$args): static
    {
        $list = clone $this;
        $list->query->setOrderBy($this->normaliseSortArgs($args));

        return $list;
    }

    /**
     * Return a new list including at most $length records, skipping
     * the first $offset ones.
     */
    public function limit(?int $length, int $offset = 0): static
    {
        $list = clone $this;
        $list->query->setLimit($length, $offset);

        return $list;
    }

    /**
     * Normalise the arguments accepted by the filter methods to a
     * field name => value map.
     *
     * @param string $function Name of the calling method, for error messages
     */
    private function normaliseFilterArgs(array $args, string $function): array
    {
        if (count($args) === 1) {
            $filters = array_values($args)[0];

            // filter('Message:Null') is a shorthand for
            // filter('Message:Null', null)
            if (is_string($filters)) {
                return [$filters => null];
            }
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
    private function addFilters(array $filters, bool $negate, bool $any): static
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
     * Build an exact match predicate, either `=` or `<>` (or `IN` /
     * `NOT IN` when an array of values is given).
     *
     * @return array Two elements: the SQL predicate and its parameters
     */
    private function exactPredicate(string $column, mixed $value, bool $negate = false): array
    {
        if ($value === null) {
            return [$column . ' IS ' . ($negate ? 'NOT ' : '') . 'NULL', []];
        }
        if (is_array($value)) {
            return $this->inPredicate($column, $value, $negate);
        }

        return [sprintf('%s %s ?', $column, $negate ? '<>' : '='), [$value]];
    }

    /**
     * Build a LIKE predicate, prepending and appending the wildcards
     * to the (scalar) value as requested.
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
     * Get the quoted column name for a field declared in $db. This
     * also protects the generated SQL against SQL injection, as the
     * field names are the only values going into the SQL string.
     */
    private function quotedColumn(string $field): string
    {
        foreach ($this->dataClass::dbFields() as $name => $spec) {
            if (strcasecmp($name, $field) === 0) {
                return sprintf('"%s"', $name);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot use "%s": not a database field of %s',
            $field,
            $this->dataClass
        ));
    }
}
