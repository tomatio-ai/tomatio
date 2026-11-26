"""Where the hooks keep their settings, state and key.

Settings: ~/.config/tomatio-hooks/config.json
  {"brain": "https://nautilus.tomatio.ai/", "roots": ["~/code"], "machine": "my Mac"}
State:    ~/.local/state/tomatio-hooks/
Key:      the macOS keychain (service "tomatio-hooks", account the brain's host),
          elsewhere ~/.config/tomatio-hooks/key (only you can read it),
          or TOMATIO_HOOKS_KEY in the environment. The key is
          "login:password" from the brain's hooks login page. It is read
          into memory for a request and never printed or logged.
"""
from __future__ import annotations

import json
import os
import socket
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlparse

SERVICE = "tomatio-hooks"


def config_dir() -> Path:
    return Path(os.environ.get("TOMATIO_HOOKS_CONFIG") or Path.home() / ".config" / "tomatio-hooks")


def state_dir() -> Path:
    return Path(os.environ.get("TOMATIO_HOOKS_STATE") or Path.home() / ".local" / "state" / "tomatio-hooks")


@dataclass
class Config:
    brain: str = ""
    roots: list[str] = field(default_factory=list)
    machine: str = ""
    # Off switches, each on by default.
    secrets: bool = True
    logging: bool = True

    @property
    def host(self) -> str:
        return urlparse(self.brain).hostname or ""

    @property
    def mcp_url(self) -> str:
        return self.brain.rstrip("/") + "/wp-json/tomatio/v1/mcp"

    def in_roots(self, cwd: str) -> bool:
        if not self.roots:
            return False
        cwd = os.path.realpath(cwd or "")
        for root in self.roots:
            root = os.path.realpath(os.path.expanduser(root))
            if cwd == root or cwd.startswith(root.rstrip("/") + "/"):
                return True
        return False


def load() -> Config:
    path = config_dir() / "config.json"
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        data = {}
    cfg = Config(
        brain=str(data.get("brain") or ""),
        roots=[str(r) for r in data.get("roots") or []],
        machine=str(data.get("machine") or socket.gethostname().split(".")[0]),
        secrets=bool(data.get("secrets", True)),
        logging=bool(data.get("logging", True)),
    )
    return cfg


def save(cfg: Config) -> Path:
    path = config_dir() / "config.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {"brain": cfg.brain, "roots": cfg.roots, "machine": cfg.machine, "secrets": cfg.secrets, "logging": cfg.logging},
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    return path


def key_file() -> Path:
    return config_dir() / "key"


def key(cfg: Config) -> str:
    """The hooks login key, or '' when there is none. Never print it."""
    return key_and_source(cfg)[0]


def key_and_source(cfg: Config) -> tuple[str, str]:
    """The key and where it came from (for check), or ('', '')."""
    env = os.environ.get("TOMATIO_HOOKS_KEY", "")
    if env:
        return env, "TOMATIO_HOOKS_KEY"
    if not cfg.host:
        return "", ""
    try:
        stored = key_file().read_text(encoding="utf-8").strip()
    except OSError:
        stored = ""
    if stored:
        return stored, str(key_file())
    try:
        out = subprocess.run(
            ["security", "find-generic-password", "-s", SERVICE, "-a", cfg.host, "-w"],
            capture_output=True,
            text=True,
            timeout=3,
        )
    except (OSError, subprocess.TimeoutExpired):
        return "", ""
    found = out.stdout.strip() if out.returncode == 0 else ""
    return (found, "the keychain") if found else ("", "")
