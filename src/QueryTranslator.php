<?php

namespace SqlParserTest;

/**
 * Translate a full parsed query.
 */
class QueryTranslator
{
    private $resource;
    private $parsed;

    /**
     * Translate a parsed SQL query.
     *
     * @param array $parsed
     *   The result of a PHPSQLParser operation on a SQL string.
     * @param string|null $resource
     *   A resource ID for the Datastore.
     * @param bool $allowJoins
     *   Whether joins are allowed or not in this query; defaults to false.
     *
     * @return DatastoreQuery
     *   A valid DatastoreQuery object.
     */
    public static function translate(array $parsed, $resource = null, bool $allowJoins = false)
    {
        $translator = new static($parsed, $resource, $allowJoins);
        return $translator->translateParsed();
    }

    /**
     * Constructor.
     *
     * @param array $parsed
     *   The result of a PHPSQLParser operation on a SQL string.
     * @param string|null $resource
     *   A resource ID for the Datastore.
     * @param bool $allowJoins
     *   Whether joins are allowed or not in this query; defaults to false.
     */
    public function __construct(array $parsed, $resource = null, bool $allowJoins = false)
    {
        $this->resource = $resource;
        $this->parsed = $parsed;
        $this->allowJoins = $allowJoins;
    }

    /**
     * Translate the loaded parsed query to a DatastoreQuery obejct.
     *
     * @return DatastoreQuery
     *   Valid DatastoreQuery object.
     */
    public function translateParsed(): DatastoreQuery
    {
        $query = [];

        if (isset($this->parsed['SELECT'])) {
            $query['properties'] = $this->translateSelect($this->parsed['SELECT']);
        }
        if (isset($this->parsed['FROM'])) {
            $query['resources'] = $this->translateFrom($this->parsed['FROM']);
            $query['joins'] = $this->translateFromJoins($this->parsed['FROM']);
        }
        $this->incorporateResource($query);
        if (isset($this->parsed['WHERE'])) {
            $query['conditions'] = $this->translateWhere($this->parsed['WHERE']);
        }
        if (isset($this->parsed['LIMIT'])) {
            $query['limit'] = $this->translateLimit($this->parsed['LIMIT']);
            $query['offset'] = $this->translateLimitOffset($this->parsed['LIMIT']);
        }
        if (isset($this->parsed['ORDER'])) {
            $query['sorts'] = $this->translateOrder($this->parsed['ORDER']);
        }

        $query = array_filter($query);
        return new DatastoreQuery($query);
    }

    /**
     * Translate the FROM clause.
     *
     * @param array $from
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Array of DatastoreQuery resources.
     */
    private function translateFrom(array $from): array
    {
        $this->incorporateResource($from);
        $this->validateJoins($from);
        $resources = [];
        foreach ($from as $resource) {
            $resources[] = TreeTranslator::translate($resource);
        }
        return array_filter($resources);
    }

    private function incorporateResource(array &$query)
    {
        if ($this->resource && isset($this->parsed['FROM'])) {
            throw new \InvalidArgumentException("You may not pass a FROM clause in a resource query.");
        } elseif ($this->resource) {
            $query['resources'] = [
                [
                    'id' => $this->resource,
                    'alias' => 't',
                ],
            ];
        }
    }

    /**
     * Translate the FROM clause to joins on another pass.
     *
     * @param array $from
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Array of DatastoreQuery joins.
     *
     * @todo Add actual JOIN support.
     */
    private function translateFromJoins(array $from)
    {
        if ($this->addJoins()) {
            throw new \Exception("Joins not yet supported in SQL queries.");
        } else {
            return null;
        }
    }

    /**
     * Check whether or not to add a joins array to the query.
     *
     * @return bool
     *   True if we should attempt to add joins.
     */
    private function addJoins(): bool
    {
        return (
            !empty($this->parsed['FROM'])
            && $this->allowJoins
            && count($this->parsed['FROM']) > 1
        );
    }

    /**
     * Ensure the FROM clause is valid given the allowJoins argument.
     *
     * @param array $from
     *   FROM array from a full PHPSQLParser array.
     *
     * @return bool
     *   Returns true if the FROM array is valid for this query.
     *
     * @throws \Exception
     *   This method will throw an exception if the FROM array violates the rules.
     */
    private function validateJoins(array $from)
    {
        if ($this->allowJoins) {
            return true;
        }
        if (count($from) > 1) {
            throw new \Exception("Joins are not permitted for this query; you have requested too many resources.");
        }
        return true;
    }

    /**
     * Translate the SELECT clause.
     *
     * @param array $select
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Array of DatastoreQuery properties.
     */
    private function translateSelect(array $select): array
    {
        $properties = [];
        try {
            foreach ($select as $property) {
                $properties[] = TreeTranslator::translate($property);
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid SELECT clause. " . $e->getMessage());
        }
        return array_filter($properties);
    }


    /**
     * WHERE clause requires more complex logic to break up.
     *
     * @param array $where
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Conditions array for DatastoreQuery.
     */
    private function translateWhere(array $where)
    {
        if (!is_array($where) || empty($where)) {
            throw new \InvalidArgumentException("Invalid WHERE clause.");
        }
        
        // If there's only one item in the where array, it must be
        // a single bracket expression.
        if (count($where) == 1) {
            return [TreeTranslator::translate($where[0])];
        }

        // Otherwise, it's some kind of expression.
        $whereGroup = TreeTranslator::translate([
            'expr_type' => 'bracket_expression',
            'sub_tree' => $where
        ]);
        // If it's a group with "and" operator, can be a simple array.
        if (isset($whereGroup['groupOperator']) && $whereGroup['groupOperator'] == 'and') {
            return $whereGroup['conditions'];
        }
        // Otherwise, it's either a single condition or a valid condition group.
        return [$whereGroup];
    }

    /**
     * Translate the LIMIT clause to a value for DatastoreQuery "limit".
     *
     * @param array $limit
     *   LIMIT array from a full PHPSQLParser array.
     *
     * @return int|null
     *   A limit value, if present.
     */
    private function translateLimit($limit)
    {
        return ((int) $limit['rowcount']) ?? null;
    }

    /**
     * Translate the LIMIT clause to a value for DatastoreQuery "offset".
     *
     * @param array $limit
     *   LIMIT array from a full PHPSQLParser array.
     *
     * @return int|null
     *   An offset value, if present.
     */
    private function translateLimitOffset($limit)
    {
        return ((int) $limit['offset']) ?? null;
    }

    /**
     * Translate the ORDER clause to DatastoreQuery "sorts".
     *
     * @param array $order
     *   ORDER array from a full PHPSQLParser array.
     *
     * @return array
     *   An array of sort arrays for DatastoreQuery.
     */
    private function translateOrder(array $order): array
    {
        $sorts = [];
        try {
            foreach ($order as $sort) {
                $sorts[] = TreeTranslator::translate($sort);
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid ORDER clause. " . $e->getMessage());
        }
        return array_filter($sorts);
    }
}
