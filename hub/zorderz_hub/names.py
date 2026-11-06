"""Every name and path this hub uses that an install may change, in one place.

Nothing here is business data: these are labels, folders and environment-variable names. The
party labels are envelope protocol constants and must equal the website's (ZCH_WEB_PARTY and
ZCH_HUB_PARTY in zorderz-apps/apps/charts/app.php); change both sides together.

Every folder can be moved with an environment variable, so the hub can run as its own user and the tests can run
in a scratch folder:

    ZORDERZ_HUB_CONFIG_DIR   the 0700 folder with hub.json, the hub's secret key and other keys
    ZORDERZ_HUB_HOME         the store, logs and caches
"""
import os
from pathlib import Path

WEB_PARTY = "zorderz-site"
HUB_PARTY = "charts-hub"

_HERE = Path(__file__).resolve().parent
HUB_ROOT = _HERE.parent  # the hub/ folder

CONFIG_DIR = Path(os.environ.get("ZORDERZ_HUB_CONFIG_DIR") or (Path.home() / ".config" / "zorderz-hub"))
HUB_CONFIG_FILE = CONFIG_DIR / "hub.json"
SECRET_FILE = CONFIG_DIR / "hub.secret"                   # the hub's own secret key (0600)
ANALYTICS_KEY_FILE = CONFIG_DIR / "analytics_key"         # the key customer code names are made with (0600)
ACCOUNTS_FILE = CONFIG_DIR / "accounts.yml"               # named business accounts (0600)

SECRET_ENV = "ZORDERZ_HUB_SECRET"
ANALYTICS_KEY_ENV = "ZORDERZ_HUB_ANALYTICS_KEY"
LMSTUDIO_TOKEN_ENV = "ZORDERZ_HUB_LMSTUDIO_TOKEN"

HUB_HOME = Path(os.environ.get("ZORDERZ_HUB_HOME") or (Path.home() / "zorderz-hub"))
DEFAULT_IMPORT_DIR = HUB_HOME / "import"                  # the business's own source files

PACK_DIR = HUB_ROOT / "packs" / "example"                 # the identity pack (a fictional example ships)
REGION_DIR = HUB_ROOT / "regions" / "example"             # the region pack (a fictional example ships)
SOURCE_ADAPTER = "zorderz_hub.adapters.billing_csv"       # turns the import folder into the clean tables

# Pack files that are reference data, not words the business's charts use. Their notes hold many
# ordinary words; counted as "the pack's own words" they would weaken the masking of names.
PACK_REFERENCE_FILES = frozenset()
