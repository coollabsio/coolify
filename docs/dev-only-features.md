# Development-only features

Development-only features must only be available when `APP_ENV=local`. Use the
`isDev()` helper consistently at every entry point so that hiding the UI is not
the only protection.

## Resource migration between servers

Resource migration is under development and is not available in production.

- **UI:** The **Migrate to another server** section in
  `resources/views/livewire/project/shared/resource-operations.blade.php` is
  rendered only when `isDev()` returns `true` and displays a **Dev** badge.
- **API:** Application, database, and service migration requests are rejected
  with `404 Not Found` outside development mode by
  `migrateResourceToDestination()` in `bootstrap/helpers/api.php`.
- **Action:** `MigrateResourceToDestination` rejects execution outside
  development mode as a defense-in-depth check.
- **Tests:** Development-mode UI, API access, and production isolation are
  covered by `tests/Feature/MigrateResourceToDestinationTest.php`.

Before promoting this feature, remove all three runtime gates together and
update the tests and this document in the same change.

## Server transfer between Coolify instances

Server transfer is under development and is not available in production.

- **UI:** Transfer and import links are rendered only when `isDev()` returns
  `true`, and the transfer Livewire components return `404 Not Found` outside
  development mode. Public Livewire actions repeat the check before doing work.
- **API:** Every endpoint handled by `ServerTransferController` returns `404 Not
  Found` unless `isDev()` returns `true`.
- **Tests:** Development-mode behavior and production isolation for all API
  endpoints, UI routes, and navigation links are covered by
  `tests/Feature/Api/ServerTransferApiTest.php` and
  `tests/Feature/Livewire/ServerTransferUiTest.php`.

Before promoting this feature, remove the API and UI runtime gates together and
update the tests and this document in the same change.
