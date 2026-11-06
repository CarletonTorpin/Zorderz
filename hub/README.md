# Zorderz Charts hub

The reference Charts hub: a small private service that holds a business's sales history as de-identified tables
and answers the Charts app's questions about it. The website never sends a question in plaintext and the hub
never listens on an open port: every request and reply is sealed (libsodium `crypto_box`) between the website's
key and the hub's key, and the hub only listens on loopback or a private overlay-network address that a tunnel or
reverse proxy the business runs forwards to.

A business can run this hub, or point the Charts app at its own service that speaks the same contract (the
envelope in `zorderz_hub/envelope.py` and the ops in `zorderz_hub/ops.py`). Without a hub the Charts app runs in
local mode and says so.

## What it needs

* A computer that stays on (a small server or desktop), Python 3.11 or newer, and the packages in
  `requirements.txt`.
* Optional: a local model server with an OpenAI-style API on `127.0.0.1` (for questions the rules can't read
  alone, and for captions). Set the model names in the config (`lmstudio.spec_model`, `caption_model`,
  `backup_model`); with none set, the question reader and the template captions do all the work.
* Optional: ffmpeg and a Whisper build, for the route planner's recorded survey drives. Choose the speech model in
  the config (`survey.whisper_model`); with none set, recordings are refused with "no speech model chosen".

## Install

Run the hub as its own user, so its keys and store are readable by nobody else.

```sh
sudo useradd --system --create-home --home-dir /srv/zorderz-hub zorderz-hub      # Linux; any dedicated user works
sudo -u zorderz-hub -H bash
cd ~ && unzip /path/to/zorderz-hub-<version>.zip -d app
python3 -m venv venv && venv/bin/pip install -r app/hub/requirements.txt
cd app/hub
../../venv/bin/python -m zorderz_hub init          # ~/.config/zorderz-hub/hub.json (0600)
```

Folders (each can be moved with an environment variable):

| What | Default | Variable |
| --- | --- | --- |
| config, keys (0700) | `~/.config/zorderz-hub` | `ZORDERZ_HUB_CONFIG_DIR` |
| store, logs, downloads | `~/zorderz-hub` | `ZORDERZ_HUB_HOME` |
| import folder | `~/zorderz-hub/import` | config `import_dir` |
| identity pack | `hub/packs/example` | config `pack_dir` |
| region pack | `hub/regions/example` | config `region_dir` |

## Keys

Three keys, each made once and never by the running hub:

```sh
python -m zorderz_hub keys hub            # the hub's key pair; prints the public key for the website
python -m zorderz_hub keys init           # the analytics key customer code names are made with
python -m zorderz_hub keys site <KEY>     # the website's public key (Charts settings, "Make a new key pair")
python -m zorderz_hub keys status         # states and fingerprints only, never a key
```

Save a copy of `~/.config/zorderz-hub/hub.secret` and `analytics_key` somewhere safe. A new analytics key gives
every customer a new code name, so the hub refuses to import with a changed key; restore the saved one instead.

On the website (Charts settings, or `wp-config.php`): the hub's address (`ZCH_HUB_URL`), the hub's public key
(`ZCH_HUB_PUBLIC`), the site's secret key (`ZCH_SITE_SECRET`) and the hosts that may call the hub
(`ZCH_LIVE_HOSTS`). A staging copy of the site never calls the hub.

## The business's data

* **Identity pack** (`pack_dir`): the business's own words. Copy `packs/example/` and replace every value:
  `profile.yml` (name, description), `catalog.yml` (products and the phrases that name them on an invoice line,
  product families, work types, detail tags), `parties.yml` (salespeople by initials), `territories.yml` (towns,
  regions, ZIP names), `mappings.yml`, `rules.yml` (counting rules, prospecting), `terms.yml`,
  `lead_sources.yml` and `line_options.json`.
* **Region pack** (`region_dir`): where the business works. Copy `regions/example/` and point each layer of
  `region.json` at the county's or state's public layers (`"kind": "arcgis"` with a `url`) or at files in the
  folder (`"kind": "file"`), mapping the hub's canonical field names onto the layer's own. A layer that isn't
  listed is simply not offered. `python -m zorderz_hub region check` says what the pack offers. The pack also
  says what its layers mean: the hazard layer's top two classes and what they are called (`classes`, and
  `labels.hazard_vh` / `hazard_h` / `hazard`), what the vector feed looks for (`vector.species`, `labels.vector`),
  the fire season (`fire_season: [first month, last month]`) and the street words that start whole families of
  streets (`generic_street_words`). Without them the hub uses neutral words, reads no fire season, and offers no
  distance-to-the-coast splits unless a `coast` layer is listed.
* **Import folder**: the billing system's export of invoice lines as CSV (one row per line; the column names
  the adapter reads are listed in `zorderz_hub/adapters/billing_csv.py`). The hub notices a changed folder within
  a minute and rebuilds the store; `python -m zorderz_hub import` rebuilds it at once. Names, e-mail addresses,
  phone numbers and streets are used on the hub computer for matching only and are never stored.
* **Named accounts** (optional): `python -m zorderz_hub accounts` writes a starting list of business customers
  that owners and admins may see by name (`~/.config/zorderz-hub/charts-accounts.yml`, 0600). Edit it, then
  import again.

## Public data

Downloaded at night, politely (off-peak hours, one request at a time, a request budget per run; set a contact
with `"downloads": {"contact": "you@example.com"}`):

* the region pack's layers (`places update`, `safety update`),
* Census ACS and Gazetteer facts by ZIP, and the region's hazard zones (`zipfacts update`),
* NOAA daily weather by ZIP (`weather update`).

Each can be run by hand. `python -m zorderz_hub region shapes zips.json` writes the ZIP outlines the website's
map draws (upload the file and set its address in the Charts settings).

## Run it as a service

systemd (Linux):

```ini
# /etc/systemd/system/zorderz-hub.service
[Unit]
Description=Zorderz Charts hub
After=network-online.target

[Service]
User=zorderz-hub
WorkingDirectory=/srv/zorderz-hub/app/hub
ExecStart=/srv/zorderz-hub/venv/bin/python -m zorderz_hub serve
Restart=on-failure
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/srv/zorderz-hub

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl enable --now zorderz-hub
```

On macOS, a LaunchAgent for the hub user with the same `ProgramArguments` and `KeepAlive` works the same way.
The hub listens on `127.0.0.1:8787` by default (`listen`, `port`); run the business's tunnel or reverse proxy to
it. If the tunnel's service file names a token file, set `ZORDERZ_HUB_TUNNEL_SERVICE_FILE` so `keys status`
checks it.

`python -m zorderz_hub selfcheck` runs the nightly self-check by hand; after the first import,
`python -m zorderz_hub selfcheck --accept` records the accepted numbers it compares against.

## Tests

```sh
cd hub && python -m unittest discover -s tests -t tests
```

The tests use invented data in a scratch folder and never touch a real key folder. The cross-language test makes
throwaway key pairs, seals with the website's PHP class and opens with the hub, and back again (skipped when PHP
with the sodium extension isn't installed).

## The assistant engine (the website's Ai Workspace)

`zorderz_hub/assistant/` answers the website's Ai Workspace app. It is off until the hub config turns it on:

```json
"assistant": {"mode": "on", "site": "https://app.example.com", "month_cap_usd": null, "instructions_file": ""}
```

* `site` is the website's own address: a turn's body is pulled only from there, once, over https, with no redirects.
* `month_cap_usd` is an optional monthly line of the engine's own, on top of the website's Ai budget (null: none; the
  website books every turn's most before it is sent and holds the engine to it).
* `instructions_file` is an optional plain text file with the business's own instructions for the assistant. The
  engine adds them after its core rules (how the website's codes and tools work). Nothing else is said about the
  business: no persona ships.

Only the caller `app:ai-workspace` gets the three assistant ops (`assistant_turn`, `assistant_status`,
`assistant_cancel`), and nothing of the store's. The hub server only writes and reads the job folder; the runner does
the work, as its own service beside the hub's:

```sh
python -m zorderz_hub assistant serve     # the runner
python -m zorderz_hub assistant status    # mode, runner, the engine's month, turns in flight
```

Each turn brings what it may use from the website, sealed: the coded message, the site tools the person may use, the
models with their prices, the region, the workspace the provider's answers must come from, and the provider key (held
in memory for that turn only). Conversations and progress are sealed at rest with a key in the hub's config folder
(`assistant-store.key`, 0600); the engine's ledger (`logs/assistant.jsonl`) holds numbers and code words only.
