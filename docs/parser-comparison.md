# SQL Parser Replacement Research

## Current: greenlion/php-sql-parser

### API
- Constructor: `new PHPSQLParser($sql, $calcPositions, $options)`
- Output access: `$parser->parsed` (public property, array)
- No return value from parse()

### Parse Tree Structure
- Top-level keys: `SELECT`, `FROM`, `WHERE`, `LIMIT`, `ORDER`
- Tree nodes have `expr_type` field (e.g., `'bracket_expression'`, `'table'`, `'colref'`, `'operator'`, `'const'`, `'alias'`)
- Key tree properties:
  - `no_quotes` - object with `parts` array (for colref, table names)
  - `parts` - array of name components
  - `alias` - object with `name` property
  - `base_expr` - original expression
  - `direction` - for sorting (`ASC`/`DESC`)
  - `sub_tree` - nested expressions
  - `LIMIT`: has `rowcount` and `offset` properties

## Target: phpmyadmin/sql-parser

### API
- Constructor: `new Parser($sql)`
- Output access: `$parser->statements[0]` - returns Statement object (e.g., `SelectStatement`)
- Can be modified and rebuilt: `$statement->build()` -> SQL string

### Parse Tree Structure
Object-oriented with typed properties on Statement classes:

**SelectStatement properties:**
- `$expr` - array of `Expression` objects (selected columns/expressions)
- `$from` - array of `Expression` objects (tables)
- `$where` - array of `Condition` objects
- `$group` - array of `GroupKeyword` objects
- `$having` - array of `Condition` objects
- `$order` - array of `OrderKeyword` objects
- `$limit` - `Limit` object (not array)
- `$options` - `OptionsArray` (for DISTINCT, etc.)
- `$join` - array of `JoinKeyword` objects
- `$union` - array of `SelectStatement` objects

**Component types:**
- `Expression`: `database`, `table`, `alias`, `column` properties
- `Condition`: structured conditions (`leftOperand`, `operator`, `rightOperand`, `expr`, `isOperator`)
- `Limit`: `offset`, `rowCount` properties
- `OrderKeyword`: `expr`, `type` properties
- `GroupKeyword`: `expr` property

### Key Differences from greenlion:
1. **OOP vs array**: Statement objects vs associative arrays
2. **Property access**: `$stmt->from[0]->table` vs `$parsed['FROM'][0]['no_quotes']['parts'][0]`
3. **Type safety**: Proper Component objects vs mixed array structures
4. **No expr_type**: Classes/properties determine type (no dynamic snake_case method dispatch needed)
5. **Direct property names**: `$table`, `$column`, `$alias` vs `no_quotes/parts` navigation
6. **Limit structure**: Object with properties vs array with rowcount/offset
7. **More modern**: Active maintenance by phpMyAdmin team, better MySQL dialect support
