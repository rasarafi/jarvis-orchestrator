# Jarvis Host Metrics Agent

A tray-resident sampler that publishes **Windows-host** vitals to a JSON file
that Jarvis reads from inside WSL. It powers the "Windows Host" band on
`/analytics` (the System panel).

This directory is the source of truth. The running copy lives at
`C:\Comm\Jarvis-HostMetrics\`.
**If you change anything here, re-copy it to `C:\Comm\Jarvis-HostMetrics\` and
restart the agent** (that directory is user-managed).

## Why this exists

Jarvis's original resource monitor reads `/proc`, which only describes the
inside of the WSL2 VM. On this machine that's a 15.6 GB slice of a 32 GB
laptop — so WSL could report "6 GB free, not swapping" while Windows was down
to 3.5 GB and climbing. Every hard hang this monitor was built to predict is
driven by *host* memory, which `/proc` cannot see.

## Why an agent instead of Jarvis just asking Windows

Measured on this machine:

| Approach | Cost per reading |
|---|---|
| `cmd.exe` (bare WSL→Windows interop floor) | 63 ms |
| Windows `python.exe` one-shot | 264 ms |
| `powershell.exe` (Windows PowerShell 5.1) | 1200–1400 ms |
| `powershell.exe` + `Win32_Processor` for CPU% | ~2200 ms |
| **Reading this agent's file from WSL** | **~4 ms** |

Interop is not the bottleneck — PowerShell's .NET startup is. Beyond speed, a
one-shot CPU reading is a ~100 ms instantaneous sample (close to noise), while a
resident process keeps a true rolling average between ticks for free.

An HTTP endpoint was rejected because WSL2 here runs in NAT mode: the gateway IP
changes across restarts and it would need an inbound firewall rule. A file needs
neither.

## What it publishes

Writes `C:\Comm\Jarvis-HostMetrics\host.json` every 5 s, via a temp file +
`os.replace()` so Jarvis can never read a half-written file.

```json
{
  "schema": 1, "ts": 1785302986.25, "host": "LAPTOP-...", "agent_pid": 16852,
  "interval_s": 5.0, "cpu_pct": 18.1, "cpu_cores": 16,
  "mem":      {"total_mb": 32042, "available_mb": 11060, "used_mb": 20981, "pct": 65.5},
  "pagefile": {"total_mb": 22528, "used_mb": 1080, "pct": 4.8},
  "commit":   {"used_mb": 30226, "limit_mb": 54570, "pct": 55.4},
  "wsl_mb": 7556,
  "top": [{"name": "vmmemWSL", "mb": 7556}, ...]
}
```

Two fields deserve explanation:

- **`commit`** is Windows' commit charge against its limit (RAM + pagefile).
  psutil does not expose it — `psutil.swap_memory()` on Windows reports the
  pagefile *only* (22.5 GB here, not the 54.5 GB limit). It comes from
  `win32api.GlobalMemoryStatusEx`. It leads free-RAM as a freeze predictor,
  because Windows starts refusing allocations near the limit.
- **`wsl_mb`** is what the WSL VM (`vmmemWSL`) costs the host. It's the bridge
  between this band and the `/proc` numbers above it in the UI.

## The tray icon is the liveness signal

Green / amber / red by host pressure, grey on error. A bare file drop can go
stale silently; an icon that disappears cannot. Jarvis also stale-checks the
`ts` field (30 s) as a backstop and shows "agent offline" rather than rendering
frozen numbers.

Right-click the icon for current readings, write/error counts, quick access to
the metrics file, and Quit.

## Install

Dependencies (Windows Python — **not** WSL's):

```
C:\Users\<you>\AppData\Local\Python\bin\python.exe -m pip install -r requirements.txt
```

Copy `agent.py` + `config.py` to `C:\Comm\Jarvis-HostMetrics\`, then create the
autostart entry (already done on this machine — `pythonw` runs it with no
console window):

```powershell
$ws = New-Object -ComObject WScript.Shell
$s = $ws.CreateShortcut("$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup\JarvisHostMetrics.lnk")
$s.TargetPath = "C:\Users\<you>\AppData\Local\Python\bin\pythonw.exe"
$s.Arguments = "agent.py"
$s.WorkingDirectory = "C:\Comm\Jarvis-HostMetrics"
$s.Save()
```

Start it now without rebooting:

```
pythonw C:\Comm\Jarvis-HostMetrics\agent.py
```

## Gotcha: the two clocks can disagree

`ts` is written in the Windows clock domain and read in WSL's, and the file's
mtime is also Windows-written — so there is no WSL-domain timestamp to compare
against. If the two clocks disagree, staleness detection is wrong in whichever
direction the offset runs.

This is not hypothetical. On 2026-07-29 this machine was **35 s apart**: the
Windows Time service (`w32time`) was **stopped**, so Windows had free-run 35 s
fast while WSL was exactly on network time. A naive `now - ts` went *negative*,
which would have let a long-dead agent read as perfectly fresh.

`HostMetricsService::ageSeconds()` therefore calibrates rather than trusting
either clock, with two guards that are both load-bearing:

1. **Calibrate only on proof of life** — the offset updates only from readings
   where `ts` actually advanced. Without this, a cold cache plus an already-dead
   agent absorbs the staleness into the "offset" and reports frozen data as live.
2. **Re-baseline when a live agent looks stale** — the baseline seeks the
   minimum delta, so it cannot follow the offset *upward* on its own. Correcting
   the host clock does exactly that, and without this guard a healthy agent
   reads as "stale" until the cache expires. (Observed for real when w32time was
   fixed.)

`HostMetricsTest` pins both directions. Don't simplify this to `now - ts`.

To check the clocks are actually in agreement, compare both against network time
rather than against each other — comparing them to each other tells you they
differ, not which one is wrong:

```bash
curl -sI https://www.cloudflare.com | grep -i '^date:'   # truth
date -u                                                   # wsl
/mnt/c/Users/<you>/AppData/Local/Python/bin/python.exe -c "import time;print(time.time())"
```

If Windows is the one adrift: `w32tm /stripchart /computer:pool.ntp.org /samples:3 /dataonly`
reports the exact offset, and the fix is `w32tm /unregister && w32tm /register`
plus a service restart from an elevated prompt.

## Config

`config.py` — output path, sample interval, top-N count, and the tray icon's
colour thresholds. The icon thresholds are cosmetic; the authoritative verdict
is `ResourcePressure::classifyHost()` server-side.
