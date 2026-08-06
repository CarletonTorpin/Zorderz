# Reviewing Zorderz for the "bug fix that is really an exploit"

Zorderz is open source and takes outside contributions, so the threat we design review around is
the **hypocrite commit**: a pull request that looks like a legitimate bug fix but quietly flips a
security property (the class of attack that hit xz-utils in 2024). The code is built to fail
closed; the risk is that a one-line change switches a protection off in a spot that reads as a
fix. This document is what a reviewer keeps in mind, and what the automated gate enforces.

## The tells (a pattern language)

Most dangerous changes are an instance of one of these. In each pair the left is the safe form
and the right is the disguised regression:

- **Allowlist to denylist** (or narrowing a denylist). "Add an exception," "narrow the marker."
  Denylists fail open. Fix direction is always toward an allowlist or an authoritative list.
- **`current_user_can(cap)` to `is_admin()` / `is_user_logged_in()` / `__return_true`.** Context
  and login are not authorization. Sold as "fix 403s" or "reduce a redundant check."
- **`hash_equals()` to `==` / `===`.** "PHP 8 deprecation," "flaky test," "simplify." Never on a
  token, signature, MAC, or nonce.
- **`(int)` / `%d` to `sanitize_text_field` / `%s` / string concat.** "Preserve non-numeric ids,"
  "handle spaces." The cast is the SQL defense.
- **A fail-closed `false` default flipped to `true`.** "Tiles blank when the plugin loads late."
  The graceful path must deny.
- **A fixed constant given a filter / option / request seam.** Adding `apply_filters(...)`,
  `get_option(...)`, or `$request->get_param(...)` to a path that feeds a filesystem write, a
  `call_user_func`, `activate_plugin`, `wp_set_auth_cookie`, or a SQL identifier.
- **A request parameter selecting identity.** Any new `email` / `user_id` / `login` reaching
  `wp_set_auth_cookie` / `wp_set_current_user`. "Disambiguate," "handle account merges."
- **Dropping an object-ownership predicate from a WHERE** ("simplify the query").
- **Removing `escapeshellarg` / hand-quoting a shell path** ("filenames with spaces break").
- **Widening a matcher** (regex, extension allowlist, IP-header list) to "catch more variants."
- **A one-part commit that is half an exploit.** Removing `escapeshellarg` is harmless until a
  later "filename fix" loosens sanitization. Review each PR as if a hostile second PR is coming,
  especially from a new contributor.

## Crown-jewel files and their invariants

These are `CODEOWNERS`-protected. A change to any of them must preserve its invariant.

| File | Invariant that must never regress |
|---|---|
| `class-zdz-apps-autoinstall.php` | Gate stays `current_user_can('install_plugins') && ('activate_plugins')`, never `is_admin()`; `$src`/`$dest`/`PLUGIN_FILE` stay fixed constants with no filter or `get_stylesheet_directory()`. |
| `class-zdz-data-portability.php` | Secrets excluded by name, name-suffix, exact-name list, and value redaction; import is JSON only (no `unserialize`); `is_safe_upload_relpath()` + `wp_check_filetype()` both guard extraction; `$acting` for re-auth is the pre-import `get_current_user_id()`. |
| `class-zdz-magic-link-bridge.php` | Login user id comes only from the server-side transient; rate limit keys on `rate_limit_ip()` (REMOTE_ADDR), never a forwarded header; the six-digit shape check and global miss cap stay. |
| `class-zdz-share-link.php` | HMAC under `wp_salt('auth')`, compared with `hash_equals`. |
| `class-zdz-rest-api.php` | Company-shared-secret writes stay `manage_options`; per-user data endpoints stay `get_current_user_id()`-scoped; no `__return_true` on a data route. |
| `class-zdz-alert-router.php` | Every notification mutation carries a per-user WHERE predicate. |
| `class-zdz-data-permissions.php` | An unknown / unrecognized role resolves to all-deny. |
| `class-zdz-kpi-metrics.php` | Endpoint stays admin-gated; revenue redaction defaults to stripping (fail closed). |
| `class-zdz-kiosk-demo.php` | Exit-to-admin requires the PIN check; stale kiosk records are purged. |
| `build.sh` | Builds only from a clean checkout; the bundled apps it vendors are the trusted payload the auto-installer runs. |

## The automated gate

Every PR runs (see `.github/workflows/security.yml`):

1. **Sentinel** (`.github/scripts/security-sentinel.sh`) — scans the added lines of the diff for
   the tells above and fails on the unambiguous ones (bare `unserialize`, `eval`, `extractTo`,
   `move_uploaded_file`, shell exec without `escapeshellarg`, `is_admin()` in the auto-installer,
   `__return_true` permission callbacks) and warns on the fuzzier ones. To consciously override a
   line, append `// sentinel:allow <reason>` — used rarely, and the reason shows up in review.
2. **Unit tests** (`tests/unit`) — pin the security invariants that need no database: the export
   never emits a credential (by name, suffix, or nested value), the importer never writes outside
   uploads, revenue is withheld from a non-privileged KPI view, and the share-link HMAC is
   domain-separated, id-bound, and constant-time. If one fails, a known vulnerability class has
   been re-opened. Fix the code, not the test.
3. **PHP lint** and **PHP_CodeSniffer** (`WordPress.Security`, `WordPress.DB.PreparedSQL` as
   errors) — broad coverage for escaping, nonces, and SQL.
4. **Integration tests** (`tests/integration`, WordPress harness) — the invariants that need
   roles, options, and `$wpdb`: unknown role resolves to all-deny, and a non-privileged user's
   KPI response carries no revenue figure.

Branch protection on `main` should require at least the sentinel, unit, and lint checks, require
a code-owner review, and disallow direct pushes.

CI supply chain: the workflow runs with `permissions: contents: read` and checks out with
`persist-credentials: false`, so a compromised build step cannot lift the workflow token. Before
this repo takes wider outside contributions, pin the third-party actions (`actions/checkout`,
`shivammathur/setup-php`) to a full commit SHA rather than a moving tag, so a hijacked action tag
cannot run in CI.
