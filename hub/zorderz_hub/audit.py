"""Hub audit log: who, when, which op, row counts, timing, model.
Never results, never question text (a typed question can hold a name)."""
import hashlib
import json
import os
import threading
import time
from pathlib import Path


class Audit:
    def __init__(self, path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.lock = threading.Lock()

    def write(self, **fields):
        fields = {"ts": time.strftime("%Y-%m-%dT%H:%M:%S"), **fields}
        if "question" in fields:
            q = fields.pop("question") or ""
            fields["q_len"] = len(q)
            fields["q_hash"] = hashlib.sha256(q.encode()).hexdigest()[:12]
        line = json.dumps(fields, ensure_ascii=False, separators=(",", ":"))
        with self.lock:
            new = not self.path.exists()
            with open(self.path, "a", encoding="utf-8") as f:
                f.write(line + "\n")
            if new:
                os.chmod(self.path, 0o600)
