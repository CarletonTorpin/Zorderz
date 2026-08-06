<!--
  Thanks for contributing to Zorderz. Fill this in honestly. The security checklist is not a
  formality: Zorderz runs businesses, and a change that quietly loosens a check is exactly the
  thing this template exists to surface. See docs/SECURITY-REVIEW.md for what each item means.
-->

## What this change does

<!-- One or two plain sentences. What was broken or missing, and what this does about it. -->

## How it was tested

<!-- Commands run, cases covered. New behavior needs a test. -->

## Security checklist

Tick each box, or explain. Any "yes" means this PR needs a second, security-focused review.

- [ ] Does it change a `permission_callback`, `current_user_can`, `check_admin_referer`, `check_ajax_referer`, or role/capability grant?
- [ ] Does it touch the apps auto-installer (`class-zdz-apps-autoinstall.php`) in any way?
- [ ] Does it touch the data export/import (secret exclusion, bundle parse, zip extraction)?
- [ ] Does it call `wp_set_auth_cookie` / `wp_set_current_user`, or source a user id / email / login from a request?
- [ ] Does it change a `hash_equals` comparison, or a signing key (`wp_salt(...)`)?
- [ ] Does it touch OTP / login-code handling, a rate limit, or `get_client_ip` / IP-header trust?
- [ ] Does it add or change an `exec` / `shell_exec` / `system` / `popen` call, or remove `escapeshellarg`?
- [ ] Does it change a file upload allowlist (extensions / MIME), or how uploads are served (`Content-Type` / `Content-Disposition`)?
- [ ] Does it build SQL with string interpolation (an `IN()` list, an `ORDER BY` / column identifier, a `$where`/`$args` pair), or add a `phpcs:ignore` on a DB line?
- [ ] Does it change the unknown-role permission fallback, KPI financial redaction, hierarchy scope, or the kiosk exit PIN?

For every box ticked: does this change **widen** who can act, **loosen** what is validated, or move a value from a constant / server source to a request / config source? If so, say why it is safe.

## For the reviewer

- [ ] The change does only what the description says (no unrelated "drive-by" edits to a security file).
- [ ] The sentinel and unit tests pass, and any new security-relevant behavior added a test.
- [ ] If a `// sentinel:allow` was added, the reason is sound.
