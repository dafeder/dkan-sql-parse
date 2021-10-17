<?php

namespace SqlParserTest;

use Exception;
use Symfony\Component\VarDumper\VarDumper;

class QueryTranslator
{
    private $resource;
    private $parsed;

    public static function translate(array $parsed, $resource = null, bool $allowJoins = false)
    {
        $translator = new static($parsed, $resource, $allowJoins);
        return $translator->translateParsed();
    }

    public function __construct(array $parsed, $resource = null, bool $allowJoins = false)
    {
        $this->resource = $resource;
        $this->parsed = $parsed;
        $this->allowJoins = $allowJoins;
    }

    private function translateParsed()
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
        
        VarDumper::dump($query);
        return new DatastoreQuery($query);
    }

    private function translateFrom(array $from)
    {
        $this->incorporateResource($from);
        $this->validateJoins($from);
        $resources = [];
        foreach ($from as $resource) {
            $resources[] = TreeTranslator::translate($resource);
        }
        return $resources;
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
     * @param array $from
     * @return true
     * @throws Exception
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

    private function translateSelect(array $select)
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
     * @param array $conditions
     * @return array
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

    private function translateLimit($limit)
    {
        return ((int) $limit['rowcount']) ?? null;
    }

    private function translateLimitOffset($limit)
    {
        return ((int) $limit['offset']) ?? null;
    }

    private function translateOrder(array $order)
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
