<?php

namespace SqlParserTest;

use RootedData\RootedJsonData;

/**
 * DatastoreQuery.
 */
class DatastoreQuery extends RootedJsonData
{
    /**
     * Constructor.
     *
     * @param string $json
     *   JSON query string from API payload.
     * @param int $rows_limit
     *   Maxmimum rows of data to return.
     */
    public function __construct(array $json, int $rows_limit = 500)
    {
        $schema = file_get_contents(__DIR__ . "/../query.json");
        $q = json_decode($schema);
        $q->properties->limit->maximum = $rows_limit;
        $q->properties->limit->default = $rows_limit;
        $schema = json_encode($q);
        parent::__construct(json_encode($json), $schema);
        $this->populateDefaults();
    }

  /**
   * For any root-level properties in the query, set defaults explicitly.
   */
    private function populateDefaults()
    {
        $schemaJson = new RootedJsonData($this->getSchema());
        $properties = $schemaJson->{"$.properties"};
        foreach ($properties as $key => $property) {
            if (isset($property['default']) && !isset($this->{"$.$key"})) {
                $this->{"$.$key"} = $property['default'];
            }
        }
    }
    
    public static function conditionOperators()
    {
        $schema = file_get_contents(__DIR__ . "/../query.json");
        $q = json_decode($schema);
        return $q->definitions->condition->properties->operator->enum;
    }

    public static function expressionOperators()
    {
        $schema = file_get_contents(__DIR__ . "/../query.json");
        $q = json_decode($schema);
        return $q->definitions->expression->properties->operator->enum;
    }

    public static function conditionGroupOperators()
    {
        $schema = file_get_contents(__DIR__ . "/../query.json");
        $q = json_decode($schema);
        return $q->definitions->conditionGroup->properties->groupOperator->enum;
    }

}
