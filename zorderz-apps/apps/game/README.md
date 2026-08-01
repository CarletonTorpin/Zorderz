# Game — Block Breaker (Zorderz apps bundle)

A casual, self-contained block-breaker rendered on an HTML canvas. It ships as
an **optional "extras" tile** in the Zorderz apps bundle: no external API calls,
no cron, and no business data. High scores are kept in one small table (one row
per user — their personal best) and shown as a leaderboard.

This is a Core-clean port of an internal block-breaker. The company-logo first
level, the initial-letter easter egg, and the analytics/chat embed hook have
been removed; identifiers were renamed off the old `ts`/`tsg` names to the short
`zg` prefix.

## At a glance

| Item | Detail |
|------|--------|
| Prefix | `zg` |
| Classes | `ZG_App` (widget), `ZG_Scores` (persistence) |
| DB table | `wp_zg_game_scores` (single row per user — personal best) |
| Option | `zg_db_version` |
| REST namespace | `ZDZ_REST_NS` (`zorderz/v1`) |
| Nonce | `wp_rest` (`X-WP-Nonce`) |
| Theme interface | `Zorderz\Widget_App_Interface` (registered via `zdz_register_apps`) |
| Roles | Any signed-in user with the app grant (`read` capability for REST) |
| External services | None |
| Cron | None |

## REST endpoints

| Method | Route | Description |
|--------|-------|-------------|
| `GET`  | `ZDZ_REST_NS/game-scores` | Leaderboard (top 10, one best per user) |
| `GET`  | `ZDZ_REST_NS/game-scores/me` | Current user's personal best |
| `POST` | `ZDZ_REST_NS/game-scores` | Submit `{ score, level, pattern }` — stored only if higher than the existing best |

All endpoints require the `read` capability and an `X-WP-Nonce: wp_rest` header.

## Data discipline

The scores table ships **empty** — activation creates the schema only, nothing
is seeded. Deactivation preserves the table. The table and DB-version option
carry deprecated-alias rename-map entries (`zdz_rename_map`) so an existing
install upgrades in place.

## Configuration

- **First-game pattern** — the first game shows a neutral block wall. A site may
  substitute its own welcome pattern with the `zg_first_pattern` filter, which
  returns a grid of row strings (8 columns each, e.g. `['88bb88bb', ...]`). No
  company letters are baked in.
- **Scale-to-fit** — on wide / landscape screens the engine letterbox-scales the
  canvas to the height budget the theme publishes as the `--zdz-game-max-h` CSS
  custom property (on `.dash-widget-container[data-app-id="game"]`). The game
  declares `:root{ --zdz-game-max-h: 0px }` as its baseline; on a theme without
  that budget it simply renders at native size.

## Themes

The engine reads `data-theme` from `<html>` and supports dark, light and a
"sunlight" Game Boy DMG dot-matrix mode, falling back to
`prefers-color-scheme`.

## Files

```
game/
├── app.php                     Bootstrap: activation, REST, theme registration
├── includes/
│   └── class-zg-scores.php     Score persistence (submit-or-update, leaderboard)
├── assets/
│   ├── css/game.css            Widget wrapper styles
│   └── js/game.js              Game engine
└── README.md                   This file
```
