# WHERE Clause Condition Processing Flow

This document details the architectural flow, tokenization, precedence parsing, and strategy selection used in the `Condition` namespace (`SqlParserTest\Condition\*`).

---

## 1. High-Level Component & Strategy Flow

This diagram shows how `WhereClauseTranslator` delegates to `WhereClauseParser`, which selects registered `ConditionTranslatorInterface` strategies for leaf nodes and leverages shared normalization services.

```mermaid
flowchart TD
    subgraph Input
        ST["SelectStatement-&gt;where"] --> WCT["WhereClauseTranslator"]
    end

    subgraph Parser ["WhereClauseParser"]
        WCT -->|"Condition[]"| TOK["1. tokenizeWhere()<br/>- Extract operator tokens ('AND'/'OR')<br/>- Count leading '(' and trailing ')'<br/>- Translate leaf conditions"]
        TOK --> AST["2. Recursive Precedence Parser<br/>- parseWhereOr()<br/>- parseWhereAnd()<br/>- parseWhereFactor()"]
        AST --> TREE["3. Boolean Condition Tree<br/>(Single condition, AND list, or OR group)"]
    end

    subgraph Strategies ["Condition Strategy Selection (Leaf Nodes)"]
        TOK --> SUP{"Check supports()"}
        SUP -->|"operator != ''"| CCT["ComparisonConditionTranslator<br/>=, &lt;&gt;, &lt;, &lt;=, &gt;, &gt;="]
        SUP -->|"regex: IN/NOT IN"| ICT["InListConditionTranslator<br/>IN (...), NOT IN (...)"]
        SUP -->|"regex: LIKE/NOT LIKE"| LCT["LikeConditionTranslator<br/>LIKE '...', NOT LIKE '...'"]
    end

    subgraph Normalization ["Shared Normalization"]
        CCT --> IP["IdentifierParser::parse<br/>e.g. 't.col' -&gt; resource: 't', property: 'col'"]
        ICT --> IP
        LCT --> IP
        CCT --> VN["ValueNormalizer::normalize<br/>numbers, booleans, string unquoting"]
        ICT --> VN
        LCT --> VN
    end

    TREE --> OUT["$query['conditions'] (DatastoreQuery JSON array)"]
```

---

## 2. Tokenization & Precedence Parsing Pipeline

This sequence diagram illustrates how a query with nested grouping and mixed operators (e.g., `WHERE a = 1 OR (b = 2 AND c LIKE "%x")`) is tokenized, parsed with standard boolean operator precedence (`AND` > `OR`), and merged into the final schema structure.

```mermaid
sequenceDiagram
    autonumber
    participant W as WhereClauseParser
    participant S as Condition Strategies
    participant H as Helpers (IdentifierParser / ValueNormalizer)

    Note over W: Step 1: Tokenization
    loop For each PhpMyAdmin Condition object
        alt isOperator == true
            W->>W: Push token {type: 'operator', value: 'and'|'or'}
        else Leaf Condition (e.g. "a = 1" or "(b = 2")
            W->>W: Count leading '(' -> emit 'lparen' tokens
            W->>S: supports(condition)?
            S->>H: IdentifierParser::parse(leftOperand)
            S->>H: ValueNormalizer::normalize(rightOperand)
            S-->>W: Return normalized condition payload
            W->>W: Push token {type: 'condition', value: payload}
            W->>W: Count trailing ')' -> emit 'rparen' tokens
        end
    end

    Note over W: Step 2: Recursive Descent Parsing (AND > OR)
    W->>W: parseWhereOr() calls parseWhereAnd()
    W->>W: parseWhereAnd() calls parseWhereFactor()
    opt If lparen token encountered
        W->>W: Recurse into parseWhereOr() until matching rparen
    end
    W->>W: mergeConditionNodes('and' / 'or') into nested conditionGroup arrays

    Note over W: Step 3: Root Normalization
    alt Top-level groupOperator is 'and'
        W-->>W: Unwrap into simple flat array of conditions
    else Top-level groupOperator is 'or' or single condition
        W-->>W: Wrap as [conditionGroup] or [singleCondition]
    end
```

---

## Key Components

| Class | Responsibility |
|---|---|
| `WhereClauseTranslator` | Clause-level entry point called during top-level statement translation. |
| `WhereClauseParser` | Coordinates tokenization, parenthesis tracking, recursive precedence climbing, and AST assembly. |
| `ConditionTranslatorInterface` | Strategy interface for parsing leaf condition expressions. |
| `ComparisonConditionTranslator` | Handles binary comparison operators (`=`, `<>`, `<`, `<=`, `>`, `>=`). |
| `InListConditionTranslator` | Handles set membership operations (`IN (...)`, `NOT IN (...)`). |
| `LikeConditionTranslator` | Handles pattern-matching operations (`LIKE '...'`, `NOT LIKE '...'`). |
| `IdentifierParser` | Normalizes column/table references into resource and property strings. |
| `ValueNormalizer` | Coerces scalar values into native PHP types (int, float, bool, unquoted strings). |
