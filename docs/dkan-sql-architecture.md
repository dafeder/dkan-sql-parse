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

---

## SQL Injection Defense & Parser Boundary Behavior

While full data security and parameter binding is handled at the DKAN/Drupal storage layer, the parsing and translation layer acts as a strict structural filter. It ensures raw SQL strings are never passed directly to downstream systems, and malicious or unsupported structures are caught early.

| Injection Vector | Example Attack Input | Parser / Translator Layer Defense | Result |
|---|---|---|---|
| **DDL / DML Statements** | `DROP TABLE users`, `UPDATE users SET ...`, `DELETE FROM ...` | `SqlStatementParser::parseSelect()` enforces that only `SelectStatement` ASTs are parsed. | `InvalidArgumentException: Only SELECT statements are supported.` |
| **Stacked / Multi-Query Injections** | `SELECT ...; DROP TABLE users; --` | Parser only extracts `statements[0]`; secondary injected statements are ignored and discarded. | Injected statement discarded; only legitimate `SELECT` translated. |
| **UNION-Based Injections** | `SELECT ... UNION SELECT password FROM users` | `StatementGuard::validate()` inspects AST and blocks `UNION` / `UNION ALL`. | `InvalidArgumentException: Prohibited SQL clauses detected` |
| **JOIN-Based Injections** | `SELECT ... JOIN users ON 1=1` | `StatementGuard::validate()` and `FromClauseTranslator` strictly prohibit multiple table sources / joins. | `InvalidArgumentException: Joins are not permitted for this query` |
| **Dangerous System Functions** | `SELECT SLEEP(5)`, `BENCHMARK(...)`, `LOAD_FILE(...)`, `VERSION()` | `AggregateFunctionTranslator` whitelists allowed functions (`sum`, `count`, `avg`, `max`, `min`). | `InvalidArgumentException: Unsupported aggregate function.` |
| **Subqueries in SELECT** | `SELECT (SELECT password FROM users) AS p` | `ArithmeticExpressionTranslator` rejects subquery AST expressions. | `InvalidArgumentException: Invalid arithmetic expression.` |
| **Comment Injection** | `SELECT ... WHERE id = 1 -- AND is_admin = 0` | Lexer cleanly strips line comments (`--`) and block comments (`/* */`) without corrupting AST. | Comment stripped; condition preserved cleanly. |
| **Tautologies (e.g. `OR 1=1`)** | `WHERE id = 1 OR 1=1` | Input is parsed into structured DatastoreQuery AST condition objects (e.g. `property: "1", operator: "=", value: 1`), preventing raw SQL breakout. | Bound safely downstream by DKAN/Drupal layer. |

