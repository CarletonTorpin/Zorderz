# Security Policy

Zorderz runs real businesses, so we take security seriously and we would rather hear about a
problem than read about it.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Instead, report it privately:

- Use GitHub's private vulnerability reporting: the **Security** tab of this repository, then
  **Report a vulnerability**.
- Or email the maintainer at the address on the GitHub profile for
  [CarletonTorpin](https://github.com/CarletonTorpin).

Include what you found, where (file and line if you can), and how to reproduce it. If you have
a proof of concept, keep it private. We will confirm receipt, work on a fix, and credit you in
the release notes if you would like.

Please give us a reasonable window to ship a fix before any public disclosure.

## Supported versions

Zorderz is pre-1.x and moves quickly. Security fixes land on `main` and in the next tagged
release. Run the latest release.

## What we consider a vulnerability

Anything that lets someone read or change data they should not, log in as someone they are not,
run code on the server, or exfiltrate a stored credential. A few areas we care about most, and
guard most closely (see `docs/SECURITY-REVIEW.md`):

- Authentication and the magic-link / login-code flow.
- The company data export and import (secrets must never travel; a bundle must never write
  outside the uploads directory or inject objects).
- The apps auto-installer, which writes to and activates code in the plugins directory.
- The role and capability model, and the shared-device (kiosk) boundary.

## For contributors

Every pull request runs an automated security gate (a hypocrite-commit sentinel, unit tests
that pin security invariants, PHP lint, and coding-standards checks). Changes to the crown-jewel
files require code-owner review. See `docs/SECURITY-REVIEW.md` for the patterns we look for.
