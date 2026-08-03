# Snipe-IT Testing

- Feature tests live in `tests/Feature/`, organized by entity (e.g. `tests/Feature/Assets/AssetsTest.php`). Unit tests live in `tests/Unit/`.
- Feature tests hit the database. The test environment uses `array` drivers for cache, session, and mail.
- Always build test data with model factories. Check for an existing custom state before setting attributes by hand.
- UI GET routes should have both a "page renders" test and a permission test — follow the naming used by siblings (`testPageRenders`, `testRequiresPermission`).

```bash
php artisan test tests/Feature/Assets/AssetsTest.php   # single file
php artisan test --filter testSomeMethod               # single method
```
