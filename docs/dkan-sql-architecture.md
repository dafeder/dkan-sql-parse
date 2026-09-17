# DKAN SQL Parse Codebase Architecture & Exploration

## Project Overview
- **Purpose**: Library and CLI utility to translate SQL queries into DKAN `DatastoreQuery` JSON payloads validated against `query.json` schema.
- **Current Parser**: `phpmyadmin/sql-parser` (^6)
- **Output Format**: JSON-serialized `DatastoreQuery` objects (`RootedJsonData` validating against `query.json` schema).

---

## DatastoreQuery Contract (`query.json`)

```json
{
  "resources": [{"id": "string", "alias": "string"}],
  "properties": [
    {"resource": "string", "property": "string", "alias": "string"},
    {"expression": {"operator": "string", "operands": []}, "alias": "string"},
    "property_name_string"
  ],
  "conditions": [
    {"resource": "string", "property": "string", "operator": "string", "value": "any"},
    {"groupOperator": "and|or", "conditions": []}
  ],
  "sorts": [{"resource": "string", "property": "string", "order": "asc|desc"}],
  "limit": 500,
  "offset": 0
}
```

### Supported Operators
- **Condition operators**: `=`, `<>`, `<`, `<=`, `>`, `>=`, `like`, `not like`, `between`, `in`, `not in`
- **Expression operators**: `+`, `-`, `*`, `/`, `%`, `sum`, `count`, `avg`, `max`, `min`
- **ConditionGroup operators**: `and`, `or`

---

## Architecture After Refactor

### Call Flow
```
./parse "SELECT ... FROM ... WHERE ..."
  ↓
ParseCommand (Symfony Console)
  ↓
SqlStatementParser::parseSelect($sql) -> SelectStatement
  ↓
QueryTranslator::translateStatement($statement, $resource)
  ↓
StatementGuard::validate($statement)
  ↓
Clause Translators:
  ├─ SelectClauseTranslator  → ColumnReferenceTranslator, AggregateFunctionTranslator, ArithmeticExpressionTranslator
  ├─ FromClauseTranslator    → Identifies table id & alias; validates single resource
  ├─ WhereClauseTranslator   → WhereClauseParser → ComparisonConditionTranslator, InListConditionTranslator, LikeConditionTranslator
  ├─ OrderClauseTranslator   → Sorts extraction & asc/desc normalization
  └─ LimitClauseTranslator   → Limit and offset extraction
  ↓
DatastoreQuery (loads query.json, validates payload schema, sets defaults)
  ↓
Pretty JSON output
```

---

## Known Constraints & DKAN Conventions
1. **Default alias `'t'`**: For DKAN compatibility, unqualified column references default to `"resource": "t"`. (See `.github/prompts/plan-omit-default-resource-alias.prompt.md` for future plan to make this optional).
2. **Joins not supported**: Single table only; queries with multiple tables throw an exception.
3. **Table identifiers with dashes (UUIDs)**: Must be backtick-quoted (e.g. `` `909ab5c6-54b6-40ac-96bc-f7198c9c734d` ``).
4. **Computed expressions must be aliased**: `(col + 4) AS n` and `COUNT(col) AS c` require explicit aliases.
