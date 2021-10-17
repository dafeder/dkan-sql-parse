# SQL Translator for DKAN

This is a small proof of concept library and CLI utility to demonstrate
the possibility of translating valid SQL strings into DatastoreQuery objects
for [DKAN](https://github.com/getdkan/dkan).

The validation is already quite comprehensive, and multiple levels of nesting 
in both SELECT and WHERE expressions are supported as long as explicitly wrapped
in parentheses.

At some point, we can add a controller for this and deprecate the old SqlEndpoint
service, to provide a more familiar and more flexible SQL query API. The response
will still contain a query object, to provide transparency and maintain the
DatastoreQuery request schema as the ultimate source of truth for Datastore
requests. This will also make it clear that we are never passing SQL directly to
the database; our multiple translation layers ensure that we never take the table
name or any unescaped input directly from API requests.

## Instalation

1. `composer install`

## Usage

Pass a SQL string. To simulate a resource argument from the DKAN API, pass a `--resource` option.

Some examples:

1. `./parse "SELECT record_number FROM tablename t WHERE something LIKE '%whatever'"`
2. `./parse "SELECT record_number, (object_id + 4) FROM tablename t WHERE (something LIKE '%whatever') AND (somethingelse = 2)"`
3. `./parse --resource=tablename "SELECT record_number WHERE something LIKE '%whatever'"`

## Limitations

* Any WHERE conditions joined by a boolean operator must be wrapped in parenthases to be properly read. For instance, `WHERE col = 1` and `WHERE (col1 = 1) AND (col2 = 2)` are both valid, but `WHERE col = 1 AND col2 = 2` will fail, even though on MySQL and most other systems it would be valid.
* Joins are not yet supported.