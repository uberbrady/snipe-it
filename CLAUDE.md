# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **PHP 8.2+** / **Laravel 12** (framework), **Laravel Mix** (webpack) for frontend assets
- **AdminLTE 2** / **Bootstrap 3** UI — Blade views. Mostly server-rendered; a handful of newer admin-settings screens use **Livewire** components (`app/Livewire/`, e.g. `LdapSettings`, `CustomFieldEditor`, `Importer`) — no Inertia.
- **Chart.js v2.9.4** — bundled at `public/js/dist/Chart.min.js`; use `horizontalBar` type (v2 API, not v3)
- Snipe-IT is a FOSS IT asset management system (assets, licenses, accessories, consumables, components, kits) — see README.md for product context.

## Common Commands

```bash
# Run all tests
php artisan test
# or
vendor/bin/phpunit

# Run a single test file
php artisan test tests/Feature/Assets/AssetsTest.php

# Run a specific test method
php artisan test --filter testSomeMethod

# Run/exclude tests by group (e.g. LDAP tests, which need the LDAP extension)
php artisan test --group=ldap
php artisan test --exclude-group=ldap

# Lint / static analysis
vendor/bin/pint          # code style (Laravel preset, see pint.json)
vendor/bin/phpstan analyse   # static analysis via Larastan, level 4 (phpstan.neon.dist)

# Build frontend assets (dev)
npm run dev

# Build for production
npm run prod

# Laravel Mix watch
npm run watch

# Tinker / REPL
php artisan tinker

# Clear caches after config/route changes
php artisan optimize:clear
```

Dev server is served via **Laravel Herd** (`herd coverage vendor/bin/phpunit --coverage-html tests/coverage/html` for coverage reports — see `composer.json` scripts).

Before running tests locally, copy `.env.testing.example` to `.env.testing`. Default test config uses in-memory SQLite (`DB_CONNECTION=sqlite_testing`); MySQL is also supported by setting the `DB_*` vars. See TESTING.md for details.

## Architecture

### Controllers

Two parallel controller trees:
- `app/Http/Controllers/` — web/UI controllers (Blade views)
- `app/Http/Controllers/Api/` — REST API controllers (JSON, used by datatables + select2)

Subdirectory groupings: `Assets/`, `Licenses/`, `Users/`, `Accessories/`, `Consumables/`, `Components/`, `Kits/`, `Account/`, `Auth/`

### API Pattern

Every API controller returns data via a **Transformer** (`app/Http/Transformers/`). Never return raw model attributes from API controllers — always pass through the transformer. `DatatablesTransformer` wraps paginated results.

```php
return (new AssetsTransformer)->transformAssets($assets, $assets->count());
```

### Authorization

All authorization goes through **Policies** (`app/Policies/`). `CheckoutablePermissionsPolicy` is the base for assets/licenses/accessories/consumables — its `checkout()` / `checkin()` methods accept `$item = null` so you can use `@can('checkout', \App\Models\Asset::class)` without an instance.

### FMCS (Full Multiple Company Support)

`Setting::getSettings()->full_multiple_companies_support == '1'` gates company-scoped filtering. The select2 API endpoints (`selectlist()` methods) accept a `companyId` query param — apply it like this:

```php
if ((Setting::getSettings()->full_multiple_companies_support == '1') && ($request->filled('companyId'))) {
    $query->where('table.company_id', $request->input('companyId'));
}
```

Pass `data-company-id="{{ $user->company_id }}"` in Blade to wire it to select2.

### Select2 AJAX Dropdowns

Use `class="js-data-ajax"` with `data-endpoint="hardware|licenses|consumables|..."`. `snipeit.js` auto-initializes these, forwarding `data-company-id` as `companyId` and `data-asset-status-type` as `statusType` to the API.

### Routes

All routes are in `routes/web.php` (UI) and `routes/api.php` (API). Breadcrumbs are defined inline using `->breadcrumbs(fn (Trail $trail) => ...)` from `tabuna/breadcrumbs`. Every UI route should have a breadcrumb.

Note: the `reports/unaccepted_assets` route is named with slashes, not dots — use `route('reports/unaccepted_assets')`.

### Translations

String keys live in `resources/lang/en-US/general.php` (and other files in that directory). Always add new UI strings as translation keys rather than hard-coding English.

### Checkout Redirect Flow

After checkout, `Helper::getRedirectOption()` reads `$request->redirect_option`. For redirecting back to the assigned user after checkout:
- Set `redirect_option=target` in the form
- Set `checkout_to_type=user` in the form
- Set `assigned_user={{ $user->id }}` in the form

### Key Helper Methods (`app/Helpers/Helper.php`)

- `Helper::deployableStatusLabelList()` — status labels for checkout forms
- `Helper::defaultChartColors()` — 10-color palette used in charts
- `Helper::getRedirectOption($request, $id, $table)` — post-checkout redirect logic

### Global View Variables

`$snipeSettings` is injected into all views via a service provider — no need to pass `Setting::getSettings()` from every controller. Use it directly in Blade.

### CSV Importing

`app/Importer/` holds per-entity importers (`AssetImporter`, `LicenseImporter`, `AccessoryImporter`, `ComponentImporter`, `ConsumableImporter`, `AssetModelImporter`, `CategoryImporter`, `UserImporter`, etc.), all extending `Importer.php`. The `Importer` Livewire component (`app/Livewire/Importer.php`) drives the UI for CSV import.

### Presenters

`app/Presenters/` (e.g. `AssetPresenter`, `AssetModelPresenter`, `AccessoryPresenter`) define the column/field configuration consumed by the Bootstrap Table datatables on index views — check these when adding or changing a column shown in a listing.

## Testing

Tests live in `tests/Feature/` (organized by entity) and `tests/Unit/`. Feature tests hit the database; the test environment uses `array` cache/session/mail drivers. Tests use factories for data setup. Use PHPUnit `#[Group('name')]` attributes to tag tests that need optional extensions/services (e.g. `LdapTest` is tagged `ldap`) so they can be excluded via `--exclude-group`.

## Contribution Policy

Per README.md / the project's AI Contribution Policy: PRs and issues generated by fully-automated tools without human review are not accepted upstream. If producing a PR for the upstream project (not an internal fork), treat Claude's output as a draft for human review, not a submission-ready artifact.
