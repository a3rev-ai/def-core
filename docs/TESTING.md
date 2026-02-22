# Testing

## Test Tiers

| Tier | When | What |
|------|------|------|
| **PR Gate** | Every PR (must pass) | Unit tests + smoke on latest WP + gitleaks |
| **Compatibility** | Scheduled / pre-release | WP 6.0 smoke, PHPUnit integration suite, WC-present run |
| **Static checks** | Report-only, scheduled | PHPCS (WPCS) + PHPStan level 3 |

## Prerequisites

- Node.js 18+ and npm
- Docker (for wp-env)
- PHP 8.0+ (for unit tests only — integration tests run inside Docker)
- [gitleaks](https://github.com/gitleaks/gitleaks) (for secret scanning)

Install dependencies:

```bash
npm install
```

## PR Gate (Required)

These must pass before merging any PR.

### 1. Unit tests (custom runner, no Docker needed)

```bash
php tests/run.php
```

Runs ~170 tests using WordPress stubs. No database or Docker required.

### 2. Smoke test on latest WordPress

```bash
npm run env:start
npm run smoke
npm run env:stop
```

Boots WordPress + Docker, runs 7 smoke checks (WP boots, plugin activates, REST routes exist, JWKS responds, auth enforced, no PHP errors, WC routes absent without WooCommerce).

### 3. Secret scanning

```bash
gitleaks detect
```

## Compatibility (Pre-Release)

### WP 6.0 minimum smoke

```bash
npm run env:start
npm run smoke:wp60
npm run env:stop
```

### PHPUnit integration suite (WC absent)

```bash
npm run env:start
npm run test:phpunit
npm run env:stop
```

~28 integration tests covering route registration, permission callbacks, bridge security, WooCommerce optionality, and JWT integration. Requires Docker (runs inside wp-env containers).

### PHPUnit WC-present group

```bash
npm run test:phpunit:wc
```

Runs only tests tagged `@group woocommerce`. Requires WooCommerce installed in the test environment.

## Static Checks (Report-Only)

These are non-blocking — they produce reports but do not fail the build.

### PHPCS (WordPress Coding Standards)

```bash
npm run env:start
npm run lint:phpcs
npm run env:stop
```

### PHPStan (level 3)

```bash
npm run env:start
npm run lint:phpstan
npm run env:stop
```

## Test File Layout

```
tests/
  run.php                  # Custom unit test runner (no WP runtime)
  test-*.php               # Unit test files (stubs-based)
  wp-stubs.php             # WordPress function stubs
  smoke/
    smoke-test.sh           # WP runtime smoke harness
  wpunit/
    bootstrap.php           # PHPUnit WP bootstrap
    test-*.php              # PHPUnit integration tests
```

- `run.php` globs `tests/test-*.php` — no recursion into `wpunit/`
- PHPUnit only scans `tests/wpunit/` — completely separate
- Both runners work independently

## npm Scripts Reference

| Script | Purpose |
|--------|---------|
| `npm run env:start` | Start wp-env Docker containers |
| `npm run env:stop` | Stop containers |
| `npm run env:destroy` | Remove containers + data |
| `npm run smoke` | Smoke test on latest WP |
| `npm run smoke:wp60` | Smoke test on WP 6.0 |
| `npm run test:unit` | Custom unit tests (`php tests/run.php`) |
| `npm run test:phpunit` | PHPUnit integration suite (WC absent) |
| `npm run test:phpunit:wc` | PHPUnit WC-present group |
| `npm run lint:phpcs` | PHPCS report (non-blocking) |
| `npm run lint:phpstan` | PHPStan report (non-blocking) |
