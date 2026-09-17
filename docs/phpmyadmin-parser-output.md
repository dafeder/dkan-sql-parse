# Example parsed result from the phpmyadmin parser

Parsing the SQL query:

```sql
SELECT * FROM table WHERE (a = 1 OR b > 3) AND c LIKE '%this' ORDER BY a LIMIT 100
```

Results in the following parsed output:

```
PhpMyAdmin\SqlParser\Statements\SelectStatement Object
(
    [expr] => Array
        (
            [0] => PhpMyAdmin\SqlParser\Components\Expression Object
                (
                    [database] => 
                    [table] => 
                    [column] => 
                    [expr] => *
                    [alias] => 
                    [function] => 
                    [subquery] => 
                )
        )

    [from] => Array
        (
            [0] => PhpMyAdmin\SqlParser\Components\Expression Object
                (
                    [database] => 
                    [table] => table
                    [column] => 
                    [expr] => `table`
                    [alias] => 
                    [function] => 
                    [subquery] => 
                )
        )

    [where] => Array
        (
            [0] => PhpMyAdmin\SqlParser\Components\Condition Object
                (
                    [identifiers] => Array
                        (
                            [0] => a
                        )
                    [isOperator] => 
                    [expr] => (a = 1
                    [leftOperand] => (a
                    [operator] => =
                    [rightOperand] => 1
                )

            [1] => PhpMyAdmin\SqlParser\Components\Condition Object
                (
                    [identifiers] => Array
                        (
                        )
                    [isOperator] => 1
                    [expr] => OR
                    [leftOperand] => 
                    [operator] => 
                    [rightOperand] => 
                )

            [2] => PhpMyAdmin\SqlParser\Components\Condition Object
                (
                    [identifiers] => Array
                        (
                            [0] => b
                        )
                    [isOperator] => 
                    [expr] => b > 3)
                    [leftOperand] => b
                    [operator] => >
                    [rightOperand] => 3)
                )

            [3] => PhpMyAdmin\SqlParser\Components\Condition Object
                (
                    [identifiers] => Array
                        (
                        )
                    [isOperator] => 1
                    [expr] => AND
                    [leftOperand] => 
                    [operator] => 
                    [rightOperand] => 
                )

            [4] => PhpMyAdmin\SqlParser\Components\Condition Object
                (
                    [identifiers] => Array
                        (
                            [0] => c
                            [1] => %this
                        )
                    [isOperator] => 
                    [expr] => c LIKE "%this"
                    [leftOperand] => c LIKE "%this"
                    [operator] => 
                    [rightOperand] => 
                )
        )

    [order] => Array
        (
            [0] => PhpMyAdmin\SqlParser\Components\OrderKeyword Object
                (
                    [expr] => PhpMyAdmin\SqlParser\Components\Expression Object
                        (
                            [database] => 
                            [table] => 
                            [column] => a
                            [expr] => a
                            [alias] => 
                            [function] => 
                            [subquery] => 
                        )
                    [type] => PhpMyAdmin\SqlParser\Components\OrderSortKeyword Enum:string
                        (
                            [name] => Asc
                            [value] => ASC
                        )
                )
        )

    [limit] => PhpMyAdmin\SqlParser\Components\Limit Object
        (
            [offset] => 0
            [rowCount] => 100
        )
)
```