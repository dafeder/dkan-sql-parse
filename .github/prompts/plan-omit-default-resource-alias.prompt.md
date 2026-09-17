# Plan: Omit Default Resource Qualifier Unless Explicitly Aliased

## Background & Goal
Currently, `'t'` is hardcoded as `IdentifierParser::DEFAULT_RESOURCE = 't'`. Unqualified column references (e.g. `SELECT record_number`, `WHERE record_number = 1`, `ORDER BY record_number`) automatically receive `"resource": "t"` in the resulting DatastoreQuery payload.

The goal is to only include `"resource"` when explicitly qualified by the user (e.g. `t.record_number`), or when strictly required by `query.json` schema constraints.

---

## Schema Feasibility (from `query.json` validation tests)

| Clause / Feature | Unqualified SQL Input | Omitted `resource` Output | Schema Validity |
|---|---|---|---|
| **WHERE Conditions** | `WHERE record_number = 1` | `{"property": "record_number", "operator": "=", "value": 1}` | **Valid** (`resource` is optional in `definitions.condition`) |
| **ORDER BY Sorts** | `ORDER BY record_number DESC` | `{"property": "record_number", "order": "desc"}` | **Valid** (`resource` is optional in `definitions.sort`) |
| **Expression Operands** | `SELECT (record_number + 4) AS n` | `operands: ["record_number", 4]` | **Valid** (`property` string allowed as operand) |
| **Aggregate Operands** | `SELECT COUNT(record_number) AS c` | `operands: ["record_number"]` | **Valid** (`property` string allowed as operand) |
| **Unqualified, Unaliased Column** | `SELECT record_number` | `"record_number"` (plain string in `properties`) | **Valid** (`properties` allows string items) |
| **Qualified Column / Condition** | `SELECT t.record_number`, `WHERE t.id = 1` | `{"resource": "t", "property": "record_number"}` | **Valid** |
| **Unqualified, Aliased Column** | `SELECT record_number AS rn` | `{"resource": "t", "property": "record_number", "alias": "rn"}` | *Schema requires `resource` on aliased objects* |

---

## Implementation Steps for Future Iteration

### 1. `IdentifierParser.php`
- Change `IdentifierParser::parse(string $identifier)` to return `['resource' => $res, 'property' => $prop]` only when `$parts > 1` (e.g. `t.col`).
- When unqualified (e.g. `col`), return `['property' => 'col']` (omit `resource` key).

### 2. Condition Translators (`ComparisonConditionTranslator`, `LikeConditionTranslator`, `InListConditionTranslator`)
- Use `IdentifierParser::parse()` result directly:
  - If `'resource'` is present in parsed identifier, include it in condition array.
  - If `'resource'` is not present, condition array will only have `property`, `operator`, `value`.

### 3. `OrderClauseTranslator.php`
- Only include `'resource'` in sort item if `!empty($part->expr->table)`.
- If unqualified: `['property' => $property, 'order' => $direction]`.

### 4. Expression Translators (`AggregateFunctionTranslator`, `ArithmeticExpressionTranslator`)
- For unqualified property operands, output plain strings (e.g. `"record_number"`).
- For qualified property operands, output `['resource' => 't', 'property' => 'record_number']`.

### 5. `ColumnReferenceTranslator.php`
- If unaliased and unqualified (`SELECT record_number`): return plain string `'record_number'`.
- If qualified (`SELECT t.record_number`): return `['resource' => 't', 'property' => 'record_number']`.
- If aliased (`SELECT record_number AS rn`):
  - Because `query.json` requires `"resource"` on aliased property objects, derive the resource from the active `FROM` table alias or fallback to table name/`'t'`.

### 6. Test Suite Updates
- Update existing assertion fixtures in `QueryTranslatorBaselineTest` and `QueryTranslatorAstTest` to expect clean unqualified properties/conditions where appropriate.
- Add dedicated test cases for both qualified (`t.col`) and unqualified (`col`) queries.
