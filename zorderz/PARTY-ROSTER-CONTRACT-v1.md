# Party Roster Contract — v1

> **Status:** New in Zorderz v1.1.0. Layer: [BOTH] — first concrete shape of the Zorderz **Party** core service (BID-2).
> **Provider:** `ZDZ_Party` (`inc/class-zdz-party.php`).
> **Should adopt:** every surface that asks "which person?" — a share/participant picker, a Jobs assignee, a Surveys owner, future @-mentions.

## The rule
A person is **selectable** wherever the platform asks "which person?" iff they are an **active registered user with a usable email** — and **nothing else** gates them. In particular, selectability is **NOT** filtered by whether the user happens to hold a given app's access grant.

This is the home of the "Ron" bug: Ron is a real, registered, emailable user, but he was absent from an app's share picker because an admin never granted him that app — so a transcript "shared with Ron" silently reached no one. Any picker that builds its own `get_users()` + capability filter will re-create this class of bug. **Read this list instead.**

## The API

### PHP (canonical)
```php
$people = ZDZ_Party::selectable_people( array(
    'exclude'      => array( get_current_user_id() ), // optional
    'include_self' => true,                            // default true
    'search'       => '',                              // optional name/email substring
) );
// => [ [ 'id'=>7, 'name'=>'Ron D', 'initials'=>'RD', 'role'=>'zdz_sales' ], ... ]
```

Excludes the shared-kiosk role (`zdz_general` — a device, not a person), users flagged inactive (`zdz_inactive` user-meta, with a read-time alias to the pre-rename `ts_inactive` key; multisite spam/deleted), and anyone without a valid email. Ordered by display name. Memoized per request.

### Filter (extend, don't rebuild)
```php
add_filter( 'zdz_selectable_people', function ( $people, $args ) {
    // ADD people or adjust presentation only.
    // NEVER re-introduce an app-grant / capability filter — that is the bug this fixes.
    return $people;
}, 10, 2 );
```

### REST
```
GET /wp-json/zorderz/v1/party/people?search=&exclude=1,2
-> 200 { "people": [ { "id", "name", "initials", "role" }, ... ] }
```
Permission: any logged-in user. **Email is intentionally not returned** — eligibility is decided server-side; a caller that must email a party resolves the address from the user id it already holds.

## What NOT to do
- Do NOT build a picker from `get_users()` filtered by `user_can($u, 'read_<app>')` or an app-access meta.
- Do NOT reuse a messaging/app-specific roster as "everyone."
- Do NOT return raw emails to the browser.

## Consumer note
A transcript-participant / share picker that currently derives candidates from an app-access-gated list (why Ron is missing) should call `ZDZ_Party::selectable_people()` (or the REST route) for its candidate set, then apply its own **explicit-share** semantics on top (a user is a *candidate* here; whether a transcript is actually shared with them stays that app's deliberate, per-transcript grant). `ZDZ_Party` decides *who can be chosen*, not *who has been granted*.

*— End of Party Roster Contract v1 —*
