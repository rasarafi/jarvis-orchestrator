#!/usr/bin/env bash
# SessionStart hook — label this session with the Claude account that started it.
#
# WHY THIS EXISTS
# Two subscriptions (cl / cl2) share one ~/.claude/projects tree through a
# symlink, and nothing written into the .jsonl identifies the account. Session
# start is the only moment the account is knowable, so it is captured here and
# POSTed to Jarvis. Signaling only — no transcript content leaves the machine,
# and nothing is written to the projects tree.
#
# The account is derived from CLAUDE_CONFIG_DIR, which only cl2 sets; the email
# comes from that config dir's own .claude.json (per-account, never symlinked).
#
# Install: referenced from ~/.claude/settings.json under hooks.SessionStart.
# Failure is always silent — a hook must never interrupt a session start.

set -uo pipefail

payload=$(cat 2>/dev/null || true)
[ -n "$payload" ] || exit 0

config_dir="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"
case "$config_dir" in
  *acct2*) account="cl2" ;;
  *)       account="cl"  ;;
esac

# cl keeps .claude.json beside the home dir; cl2 keeps it inside its config dir.
if [ "$account" = "cl" ]; then
  config_json="$HOME/.claude.json"
else
  config_json="$config_dir/.claude.json"
fi

read -r session_id email <<EOF2
$(SESSION_PAYLOAD="$payload" CONFIG_JSON="$config_json" python3 - <<'PY' 2>/dev/null || echo " "
import json, os
sid = email = ""
try:
    sid = json.loads(os.environ["SESSION_PAYLOAD"]).get("session_id", "") or ""
except Exception:
    pass
try:
    with open(os.environ["CONFIG_JSON"]) as fh:
        email = json.load(fh).get("oauthAccount", {}).get("emailAddress", "") or ""
except Exception:
    pass
print(sid, email)
PY
)
EOF2

[ -n "${session_id:-}" ] || exit 0

body=$(SID="$session_id" ACCOUNT="$account" EMAIL="${email:-}" python3 -c '
import json, os
out = {"session_id": os.environ["SID"], "account": os.environ["ACCOUNT"]}
if os.environ.get("EMAIL"):
    out["email"] = os.environ["EMAIL"]
print(json.dumps(out))
' 2>/dev/null) || exit 0

curl -s -m 2 -X POST -H 'Content-Type: application/json' \
  -d "$body" http://localhost:7170/api/sessions/account >/dev/null 2>&1 || true

exit 0
