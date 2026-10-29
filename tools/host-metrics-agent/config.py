# Jarvis Host Metrics Agent — configuration
# Shared by agent.py. Mirrors the duplex-relay config convention.

# Where the metrics file is written. Jarvis reads this same path from WSL as
# /mnt/c/Comm/Jarvis-HostMetrics/host.json — keep the two in sync if you move it.
OUTPUT_PATH = r"C:\Comm\Jarvis-HostMetrics\host.json"

# Seconds between samples. Jarvis treats the file as stale after 30s, so keep
# this comfortably under that.
INTERVAL = 5.0

# How many top memory consumers to report.
TOP_N = 6

# Process names that ARE the WSL virtual machine. Their memory is what Jarvis
# itself costs the host, so it's reported separately from the rest.
WSL_PROCESS_NAMES = {"vmmem", "vmmemwsl", "vmmemWSL"}

# Tray icon thresholds (cosmetic only — the authoritative hang verdict is
# computed server-side in ResourcePressure::classifyHost).
ICON_WARN_AVAIL_MB = 4096
ICON_CRIT_AVAIL_MB = 2048
ICON_WARN_COMMIT_PCT = 85
ICON_CRIT_COMMIT_PCT = 95
