"""Keep secrets out of transcripts (B67).

Two steps, one list of patterns for every tool:

* prevent: before a shell command runs, refuse the ones known to print a
  secret, and tell the AI the safe form (capture it in a variable).
* mask: after a tool call, find known token shapes in what it printed, so
  they are never sent to the brain, and warn the AI not to repeat them.
* wrap: where a tool cannot replace what a shell command printed (Codex),
  run the command with its output piped through mask_stream first, so the
  raw value never reaches the tool or its transcript.
"""
from __future__ import annotations

import re
import shlex
from dataclasses import dataclass
from typing import IO

SAFE_FORM = (
    "Capture the secret in a variable instead, for example "
    "TOKEN=$(security find-generic-password -s NAME -w), use \"$TOKEN\" in the next command, "
    "and never print it."
)


@dataclass(frozen=True)
class Rule:
    id: str
    pattern: re.Pattern
    why: str
    safe: str = SAFE_FORM


PREVENT: tuple[Rule, ...] = (
    Rule(
        "keychain-print",
        re.compile(r"\bsecurity\s+find-(?:generic|internet)-password\b[^|;&]*\s-[a-z]*[wg]\b"),
        "prints a password from the keychain",
    ),
    Rule(
        "git-credential-fill",
        re.compile(r"\bgit\s+credential\s+fill\b"),
        "prints the stored git password",
        "Read only the password into a variable: "
        "TOKEN=$(printf 'protocol=https\\nhost=HOST\\n\\n' | git credential fill | sed -n 's/^password=//p').",
    ),
    Rule(
        "cli-auth-token",
        re.compile(
            r"\b(?:gh|fly|flyctl|heroku|vercel|netlify|wrangler|doctl|gcloud|az)\b[^|;&]*\bauth\b[^|;&]*"
            r"\b(?:token|print-access-token|print-identity-token|get-access-token)\b"
        ),
        "prints a login token",
    ),
    Rule(
        "env-dump",
        re.compile(r"(?:^|[;&|(]\s*)(?:env|printenv|export\s+-p|set)\s*(?=$|[;&|)])"),
        "prints every environment variable, tokens included",
        "Print only the variable you need, and only one that is not a secret: printenv HOME.",
    ),
    Rule(
        "echo-secret",
        re.compile(
            r"\b(?:echo|printf)\b[^;&|]*\$\{?[A-Za-z_]*"
            r"(?:TOKEN|SECRET|PASSWORD|PASSWD|PASS|API_?KEY|PRIVATE_KEY|ACCESS_KEY|CREDENTIAL)[A-Za-z0-9_]*\}?",
            re.I,
        ),
        "prints a secret variable",
        "Check it without printing it: [ -n \"$TOKEN\" ] && echo set, or echo ${#TOKEN} for its length.",
    ),
    Rule(
        "secret-file",
        re.compile(
            r"\b(?:cat|less|more|head|tail|bat|nl|strings|xxd|od)\b[^;&|]*?"
            r"(?:\.netrc|\.git-credentials|(?<![\w.-])\.env(?:\.(?!example|sample|template|dist)[\w-]+)?(?![\w.-])"
            r"|credentials(?:\.json)?(?![\w.-])|\.npmrc|\.pypirc|gh/hosts\.yml|\.fly/config\.yml|\.aws/credentials"
            r"|\.docker/config\.json|id_(?:rsa|ed25519|ecdsa)(?![\w.-])|\.codex/auth\.json|\.claude/\.credentials\.json)"
        ),
        "prints a file that holds secrets",
        "Read only the key you need into a variable, or check the file without printing it (grep -c NAME FILE).",
    ),
    Rule(
        "wp-app-password",
        re.compile(r"\bwp\s+user\s+application-password\s+create\b"),
        "prints a new application password",
        "Capture it: PW=$(wp user application-password create USER NAME --porcelain), and never print it.",
    ),
    Rule(
        "curl-verbose-auth",
        re.compile(r"\bcurl\b(?=[^;&|]*(?:\s-v\b|\s-[a-zA-Z]*v[a-zA-Z]*\b|--verbose|--trace))(?=[^;&|]*(?:Authorization|-u\s|--user\s|PRIVATE-TOKEN))", re.I),
        "prints the Authorization header (curl -v shows request headers)",
        "Drop -v, or use -w '%{http_code}' to see the status.",
    ),
)

# A secret piped into one of these is used, not printed.
_CONSUMER = re.compile(r"\|\s*(?:pbcopy\b|[^|;&]*--password-stdin\b|[^|;&]*--with-token\b|[^|;&]*--token-stdin\b|[^|;&]*\bstdin\b)")
_CAPTURE = re.compile(r"\$\((?:[^()]|\([^()]*\))*\)|`[^`]*`")
_HEREDOC = re.compile(r"<<-?\s*['\"]?(\w+)['\"]?.*?\n.*?\n\1\b", re.S)
_QUOTED = re.compile(r"'[^']*'")


def _strip(command: str) -> str:
    """The parts of a command whose output reaches the terminal."""
    text = _HEREDOC.sub(" ", command)
    prev = None
    while prev != text:
        prev = text
        text = _CAPTURE.sub(" ", text)
    return text


@dataclass(frozen=True)
class Refusal:
    rule: str
    reason: str


def check_command(command: str) -> Refusal | None:
    """A refusal when the command would print a secret, else None."""
    if not command:
        return None
    visible = _strip(command)
    for rule in PREVENT:
        for m in rule.pattern.finditer(visible):
            rest = visible[m.end():]
            segment = re.split(r"[;&]|\n", rest, maxsplit=1)[0]
            if _CONSUMER.search(segment):
                continue
            if rule.id in ("echo-secret", "env-dump") and _in_single_quotes(visible, m.start()):
                continue
            if re.match(r"[^|;&]*>\s*(?!&)\S", segment) and not re.match(r"[^|;&]*>\s*/dev/(?:stdout|stderr|tty)", segment):
                # Written to a file, not printed.
                continue
            return Refusal(
                rule.id,
                f"TomaTío hooks: this command {rule.why}, and the output would land in the transcript. {rule.safe}",
            )
    return None


def _in_single_quotes(text: str, pos: int) -> bool:
    return any(q.start() < pos < q.end() for q in _QUOTED.finditer(text))


@dataclass(frozen=True)
class Shape:
    id: str
    pattern: re.Pattern
    group: int = 0


SHAPES: tuple[Shape, ...] = (
    Shape("private-key", re.compile(r"-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----[\s\S]*?-----END (?:[A-Z ]+ )?PRIVATE KEY-----")),
    Shape("anthropic-key", re.compile(r"\bsk-ant-[A-Za-z0-9_-]{20,}")),
    Shape("openai-key", re.compile(r"\bsk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{32,}")),
    Shape("github-token", re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})")),
    Shape("aws-key", re.compile(r"\b(?:AKIA|ASIA)[0-9A-Z]{16}\b")),
    Shape("slack-token", re.compile(r"\bxox[abposr]-[A-Za-z0-9-]{10,}")),
    Shape("fly-token", re.compile(r"\bFlyV1\s+fm\d_[A-Za-z0-9_+/=,-]{20,}")),
    Shape("stripe-key", re.compile(r"\b[rs]k_(?:live|test)_[A-Za-z0-9]{20,}")),
    Shape("google-key", re.compile(r"\bAIza[0-9A-Za-z_-]{35}\b")),
    Shape("jwt", re.compile(r"\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}")),
    Shape("auth-header", re.compile(r"(?i)\b(?:authorization|proxy-authorization)\s*[:=]\s*[\"']?(?:bearer|basic|token)\s+([A-Za-z0-9._~+/=-]{12,})"), 1),
    Shape("bearer", re.compile(r"(?i)\bbearer\s+([A-Za-z0-9._~+/=-]{20,})"), 1),
    Shape("credential-line", re.compile(r"(?m)^password=(.+)$"), 1),
    Shape(
        "named-secret",
        re.compile(
            r"(?i)[\"']?\b(?:access_token|refresh_token|client_secret|api_key|apikey|secret_key|private_token|"
            r"app_password|password|passwd|token|secret)\b[\"']?\s*[:=]\s*[\"']?([A-Za-z0-9._~+/=-]{16,})"
        ),
        1,
    ),
    Shape("wp-app-password", re.compile(r"\b[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}\b")),
)


def find(text: str) -> list[str]:
    """The kinds of secret found in text (no values)."""
    if not text:
        return []
    kinds = []
    for shape in SHAPES:
        if shape.pattern.search(text):
            kinds.append(shape.id)
    return kinds


def mask(text: str) -> tuple[str, list[str]]:
    """text with every known secret replaced, and the kinds found."""
    if not text:
        return text, []
    kinds: list[str] = []
    for shape in SHAPES:
        def repl(m: re.Match, shape: Shape = shape) -> str:
            kinds.append(shape.id)
            if shape.group:
                start, end = m.span(shape.group)
                return m.group(0)[: start - m.start()] + f"[hidden {shape.id}]" + m.group(0)[end - m.start():]
            return f"[hidden {shape.id}]"
        text = shape.pattern.sub(repl, text)
    seen: list[str] = []
    for k in kinds:
        if k not in seen:
            seen.append(k)
    return text, seen


# Wrapping a shell command so its output is masked before the tool sees it.
_WRAP_HEAD = "(set -o pipefail) 2>/dev/null && set -o pipefail; { "
_WRAP_TAIL = "\n} 2>&1 | "
_WRAPPED = re.compile(r"^\(set -o pipefail\) 2>/dev/null && set -o pipefail; \{ (.*)\n\} 2>&1 \| \S+ \S*tomatio-hook'? mask$", re.S)
_DOUBLE_QUOTED = re.compile(r'"(?:[^"\\]|\\.)*"')
# Left running after the command returns: the pipe would stay open.
_DETACHES = re.compile(r"(?<![&>|<])&(?![&>])|\b(?:nohup|disown|setsid|daemonize)\b|\bscreen\s+-d|\btmux\b")


def mask_command(script: str, python: str) -> str:
    """The shell form of: pipe into this masker."""
    return f"{shlex.quote(python)} {shlex.quote(script)} mask"


def wrap(command: str, masker: str) -> str | None:
    """command with everything it prints piped through masker, or None when
    wrapping it is not safe (empty, already wrapped, or it leaves a process
    running that would hold the pipe open). stderr joins stdout; the exit
    status stays the command's where the shell has pipefail."""
    if not command or not command.strip() or _WRAPPED.match(command):
        return None
    bare = _QUOTED.sub("''", _DOUBLE_QUOTED.sub('""', _HEREDOC.sub(" ", command)))
    if _DETACHES.search(bare):
        return None
    return f"{_WRAP_HEAD}{command}{_WRAP_TAIL}{masker}"


def unwrap(command: str) -> str:
    """The command as the AI wrote it, without our masking wrapper."""
    m = _WRAPPED.match(command or "")
    return m.group(1) if m else command


_PEM_BEGIN = re.compile(r"-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----")
_PEM_END = re.compile(r"-----END (?:[A-Z ]+ )?PRIVATE KEY-----")


def mask_stream(inp: IO[bytes], out: IO[bytes]) -> None:
    """Copy inp to out line by line with every known secret hidden. A
    private key block is held back and replaced whole."""
    in_key = False
    for raw in iter(inp.readline, b""):
        line = raw.decode("utf-8", "surrogateescape")
        if in_key:
            if _PEM_END.search(line):
                in_key = False
                out.write(("[hidden private-key]" + _PEM_END.split(line, 1)[1]).encode("utf-8", "surrogateescape"))
                out.flush()
            continue
        if _PEM_BEGIN.search(line) and not _PEM_END.search(line):
            head = _PEM_BEGIN.split(line, 1)[0]
            out.write(mask(head)[0].encode("utf-8", "surrogateescape"))
            in_key = True
            continue
        out.write(mask(line)[0].encode("utf-8", "surrogateescape"))
        out.flush()
    if in_key:
        out.write(b"[hidden private-key]\n")
    out.flush()
