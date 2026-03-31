# Migration Best Practices

## Generate Migrations with Artisan

Always use `php artisan make:migration` for consistent naming and timestamps.

Incorrect (manually created file):
```php
// database/migrations/posts_migration.php  ← wrong naming, no timestamp
```

Correct (Artisan-generated):
```bash
php artisan make:migration create_posts_table
php artisan make:migration add_slug_to_posts_table
```

## Use `constrained()` for Foreign Keys

Automatic naming and referential integrity.

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

// Non-standard names
$table->foreignId('author_id')->constrained('users');
```

## Never Modify Deployed Migrations

Once a migration has run in production, treat it as immutable. Create a new migration to change the table.

Incorrect (editing a deployed migration):
```php
// 2024_01_01_create_posts_table.php — already in production
$table->string('slug')->unique(); // ← added after deployment
```

Correct (new migration to alter):
```php
// 2024_03_15_add_slug_to_posts_table.php
Schema::table('posts', function (Blueprint $table) {
    $table->string('slug')->unique()->after('title');
});
```

## Add Indexes in the Migration

Add indexes when creating the table, not as an afterthought. Columns used in `WHERE`, `ORDER BY`, and `JOIN` clauses need indexes.

Incorrect:
```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('status');
    $table->timestamps();
});
```

Correct:
```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->index();
    $table->string('status')->index();
    $table->timestamp('shipped_at')->nullable()->index();
    $table->timestamps();
});
```

## Mirror Defaults in Model `$attributes`

When a column has a database default, mirror it in the model so new instances have correct values before saving.

```php
// Migration
$table->string('status')->default('pending');

// Model
protected $attributes = [
    'status' => 'pending',
];
```

## Write Reversible `down()` Methods by Default

Implement `down()` for schema changes that can be safely reversed so `migrate:rollback` works in CI and failed deployments.

```php
public function down(): void
{
    Schema::table('posts', function (Blueprint $table) {
        $table->dropColumn('slug');
    });
}
```

For intentionally irreversible migrations (e.g., destructive data backfills), leave a clear comment and require a forward fix migration instead of pretending rollback is supported.

## Keep Migrations Focused

One concern per migration. Never mix DDL (schema changes) and DML (data manipulation).

Incorrect (partial failure creates unrecoverable state):
```php
public function up(): void
{
    Schema::create('settings', function (Blueprint $table) { ... });
    DB::table('settings')->insert(['key' => 'version', 'value' => '1.0']);
}
```

Correct (separate migrations):
```php
// Migration 1: create_settings_table
Schema::create('settings', function (Blueprint $table) { ... });

// Migration 2: seed_default_settings
DB::table('settings')->insert(['key' => 'version', 'value' => '1.0']);
```