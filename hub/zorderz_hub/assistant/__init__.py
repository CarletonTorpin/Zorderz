"""The assistant engine beside the hub: answers the website's Ai Workspace.

The hub server answers three ops for exactly one caller, the website's relay (`app:ai-workspace`, access.py):
assistant_turn (a ticket: a turn id and a single-use link to the turn's body), assistant_status (the turn's state,
its next page of coded progress and answer, and the engine's month) and assistant_cancel. The ops only write and read
files (link.py), so the runner, a separate process (runner.py, `python -m zorderz_hub assistant serve`), does the
work: it pulls each turn's sealed body from the website, runs it (engine.py) with the provider the website names
(provider.py), asks the website's own apps through sealed tool calls, and keeps the conversation sealed at rest
(store.py) and its spend in a numbers-only ledger with holds (ledger.py).

Off by default: `assistant.mode` "on" and `assistant.site` (the website's own https address) in the hub config turn
it on. Nothing here names a business, a person, a model, a price or a workspace: each turn brings those from the
website, and the business's own instructions, if any, come from a plain text file it writes (instructions_file).
"""
from .link import Link, LinkError, health, status  # noqa: F401
from .runner import serve  # noqa: F401
