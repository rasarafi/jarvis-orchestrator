"""
Jarvis Host Metrics Agent
=========================

A tray-resident sampler that publishes Windows-host vitals to a JSON file which
Jarvis (running inside WSL) reads at ~4ms.

Why a resident agent instead of Jarvis shelling out on demand:
  - powershell.exe from WSL costs ~1.2s per call (.NET startup), and CPU% via
    Win32_Processor adds ~0.9s on top.
  - A one-shot CPU reading is a ~100ms instantaneous snapshot — close to noise.
    A resident process keeps a rolling average between ticks for free.
  - HTTP would need the WSL gateway IP (unstable across restarts under NAT) plus
    an inbound firewall rule. A file needs neither.

The tray icon is the liveness signal: a bare file drop can go stale silently,
an icon that vanishes cannot. Jarvis also stale-checks the `ts` field as a backstop.

Run with pythonw.exe for no console window. See README.md for autostart setup.
"""

import json
import os
import socket
import sys
import threading
import time

import psutil
import win32api
from PIL import Image, ImageDraw
import pystray

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import config  # noqa: E402

SCHEMA_VERSION = 1
MB = 1048576

# Tray palette — mirrors the deck's ok/gold/bad semantics.
COLORS = {
    "ok": (46, 160, 108),
    "warning": (196, 148, 42),
    "critical": (200, 66, 66),
    "error": (110, 110, 118),
}

_state = {"status": "ok", "detail": "starting…", "writes": 0, "errors": 0}
_stop = threading.Event()


def commit_charge():
    """
    Windows commit charge, which psutil does not expose.

    psutil.swap_memory() on Windows reports the pagefile ONLY (~22.5 GB here),
    not the commit limit (~54.5 GB = RAM + pagefile). Commit vs limit is the
    better pressure signal: it's what climbs before allocations start failing.
    """
    g = win32api.GlobalMemoryStatusEx()
    limit_mb = g["TotalPageFile"] // MB
    avail_mb = g["AvailPageFile"] // MB
    used_mb = max(0, limit_mb - avail_mb)
    pct = round(used_mb / limit_mb * 100, 1) if limit_mb else 0.0
    return {"used_mb": used_mb, "limit_mb": limit_mb, "pct": pct}


def top_processes(n):
    """Top-N processes by working set, plus the WSL VM's total in its own bucket.

    Returns (rows, wsl_mb). Processes vanish mid-iteration constantly on Windows,
    so per-process errors are swallowed rather than failing the whole tick.
    """
    rows, wsl_mb = [], 0
    for p in psutil.process_iter(["name", "memory_info"]):
        try:
            info = p.info
            mem = info.get("memory_info")
            if not mem:
                continue
            mb = mem.rss // MB
            if mb <= 0:
                continue
            name = info.get("name") or "?"
            if name in config.WSL_PROCESS_NAMES:
                wsl_mb += mb
            rows.append({"name": name, "mb": mb})
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            continue
    rows.sort(key=lambda r: r["mb"], reverse=True)
    return rows[: config.TOP_N], wsl_mb


def icon_status(avail_mb, commit_pct):
    """Cosmetic tray colour only. The real verdict is computed server-side."""
    if avail_mb < config.ICON_CRIT_AVAIL_MB or commit_pct >= config.ICON_CRIT_COMMIT_PCT:
        return "critical"
    if avail_mb < config.ICON_WARN_AVAIL_MB or commit_pct >= config.ICON_WARN_COMMIT_PCT:
        return "warning"
    return "ok"


def collect(cpu_pct):
    vm = psutil.virtual_memory()
    sw = psutil.swap_memory()
    commit = commit_charge()
    top, wsl_mb = top_processes(config.TOP_N)

    return {
        "schema": SCHEMA_VERSION,
        "ts": time.time(),
        "host": socket.gethostname(),
        "agent_pid": os.getpid(),
        "interval_s": config.INTERVAL,
        "cpu_pct": round(cpu_pct, 1),
        "cpu_cores": psutil.cpu_count(logical=True),
        "mem": {
            "total_mb": vm.total // MB,
            "available_mb": vm.available // MB,
            "used_mb": (vm.total - vm.available) // MB,
            "pct": round(vm.percent, 1),
        },
        # Pagefile only — see commit_charge() for why this is not the commit limit.
        "pagefile": {
            "total_mb": sw.total // MB,
            "used_mb": sw.used // MB,
            "pct": round(sw.percent, 1),
        },
        "commit": commit,
        "wsl_mb": wsl_mb,
        "top": top,
    }


def write_atomic(payload):
    """
    Write via temp file + os.replace so Jarvis never reads a half-written file.
    os.replace is atomic on the same volume on Windows.
    """
    path = config.OUTPUT_PATH
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, separators=(",", ":"))
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, path)


def sample_loop(icon):
    # Prime the CPU counter: the first cpu_percent(None) call always returns 0.0
    # because it has no previous reading to diff against.
    psutil.cpu_percent(interval=None)
    _stop.wait(1.0)

    while not _stop.is_set():
        try:
            # interval=None returns the average since the previous call — i.e.
            # a true rolling average over the tick, at zero blocking cost.
            payload = collect(psutil.cpu_percent(interval=None))
            write_atomic(payload)

            status = icon_status(payload["mem"]["available_mb"], payload["commit"]["pct"])
            _state.update(
                status=status,
                writes=_state["writes"] + 1,
                detail="{} MB free · commit {}% · CPU {}%".format(
                    payload["mem"]["available_mb"], payload["commit"]["pct"], payload["cpu_pct"]
                ),
            )
        except Exception as exc:  # keep the agent alive; surface via the icon
            _state.update(status="error", errors=_state["errors"] + 1, detail=f"{type(exc).__name__}: {exc}")

        refresh(icon)
        _stop.wait(config.INTERVAL)


def make_image(status):
    img = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.ellipse((6, 6, 58, 58), fill=COLORS[status])
    # Inner notch keeps the glyph legible on both light and dark taskbars.
    d.ellipse((24, 24, 40, 40), fill=(255, 255, 255, 235))
    return img


def refresh(icon):
    icon.icon = make_image(_state["status"])
    icon.title = f"Jarvis Host Metrics — {_state['status']}\n{_state['detail']}"


def main():
    icon = pystray.Icon("jarvis-host-metrics", make_image("ok"), "Jarvis Host Metrics")
    icon.menu = pystray.Menu(
        pystray.MenuItem(lambda _: _state["detail"], None, enabled=False),
        pystray.MenuItem(lambda _: f"writes {_state['writes']} · errors {_state['errors']}", None, enabled=False),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("Open metrics file", lambda: os.startfile(config.OUTPUT_PATH)),
        pystray.MenuItem("Open folder", lambda: os.startfile(os.path.dirname(config.OUTPUT_PATH))),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("Quit", lambda: (_stop.set(), icon.stop())),
    )

    threading.Thread(target=sample_loop, args=(icon,), daemon=True).start()
    icon.run()


if __name__ == "__main__":
    main()
