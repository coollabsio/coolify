# Migration Best Practices

## Generate Migrations with Artisan

Use `php artisan make:migration` to generate the timestamped filename and migration structure.

```bash
php artisan make:migration create_posts_table
php artisan make:migration add_slug_to_posts_table
```

## Define Foreign-Key Constraints Deliberately

Use `constrained()` when its naming conventions and default actions match the relationship. Specify the table or delete behavior when they do not.

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->foreignId('author_id')->constrained('users');
```

Do not add a duplicate single-column index without checking the database driver's treatment of foreign-key indexes and the indexes already created by the migration.

## Treat Deployed Migrations as Immutable

After a migration has run in a shared or production environment, create a new migration for subsequent changes. Editing the old file makes fresh installations differ from upgraded installations.

For a local migration that has not been shared or deployed, editing and rerunning it may be simpler.

## Design Indexes for Real Queries

Add indexes based on query patterns, selectivity, write cost, and the database's ability to use composite indexes. A column appearing in `WHERE`, `ORDER BY`, or `JOIN` does not automatically need its own index.

Declare each selected index in the schema migration that creates or changes the relevant table. Confirm important indexes with representative data and the database's query plan, and avoid redundant indexes whose leading columns duplicate an existing index without serving a distinct query. See the database performance and advanced query rules for index selection and column-order guidance.

## Stage Changes That Affect Existing Rows

Adding a required or unique column to a populated table often needs multiple deployment-safe steps. Add a nullable column, deploy code that can handle both states, backfill existing rows in bounded chunks, then add the required constraint or index after the data is valid.

Do not assume this migration is safe on a populated table:

```php
$table->string('slug')->unique();
```

Large backfills are usually better implemented as an observable, restartable command or job than inside a schema migration. Small deterministic data changes may be reasonable in a migration when their locking, transaction, and deployment behavior is understood.

## Mirror Defaults Only When Unsaved Models Need Them

A database default is applied when a row is inserted, not when a model is instantiated. Mirror the value in the model's `$attributes` only when application code must observe that default before persistence, and keep both definitions synchronized.

```php
// Migration
$table->string('status')->default('pending');

// Model
protected $attributes = [
    'status' => 'pending',
];
```

## Make Rollbacks Honest

Implement `down()` when the change can be safely reversed. A rollback that drops populated columns or cannot restore transformed data is destructive even if it is syntactically reversible; document that limitation and prefer a forward-fix migration in production.

## Keep Migrations Focused

Keep each migration small enough to reason about, deploy, and reverse. Separate long-running backfills from schema changes when doing so reduces locks and supports phased deployment, but do not split related operations merely to enforce a blanket separation between data definition and data manipulation.
