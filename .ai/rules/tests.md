---
paths:
  - 'tests/**'
---

# Tests

## Test environment uses array drivers

Feature tests hit the database. The test environment uses `array` drivers for cache, session, and mail.

## Database refresh comes from the base TestCase
`Tests\TestCase` already applies `LazilyRefreshDatabase` and seeds settings via `InitializesSettings`. Do not add `RefreshDatabase`, `DatabaseTransactions`, or `DatabaseMigrations` to an individual test.

## Test methods are snake_case
Name test methods in snake_case: `test_page_renders()`, `test_requires_permission()`. Never camelCase.

## UI GET routes need a render and a permission test

Every UI GET route should have both a `test_page_renders` test and a `test_requires_permission` test.

## Authenticate API tests with actingAsForApi()
Use `$this->actingAsForApi($user)` in API tests and `$this->actingAs($user)` in UI tests.
