#!/bin/bash
# dev.sh - Development server manager for Jarvis v2

DIR="$(cd "$(dirname "$0")" && pwd)"
PID_DIR="$DIR/.pids"
LOG_DIR="$DIR/.logs"
mkdir -p "$PID_DIR" "$LOG_DIR"

# Serialize invocations. Multiple Claude sessions run "restart dev server"
# concurrently; because stop falls back to killing whatever holds the ports, an
# interleaved stop/start pair leaves the stack down. One at a time, max 90s wait.
# fd 9 is explicit so spawn() can close it in children — services must NOT inherit
# the lock, or they'd hold it for their whole lifetime and block the next run.
exec 9>"$PID_DIR/dev.lock"
if ! flock -w 90 9; then
    echo "  ⚠ another dev.sh has held the lock for 90s — aborting"
    exit 1
fi

# Once we hold the lock, run to completion. A dropped VS Code/SSH connection
# SIGTERMs the invoking shell's process group; being killed between stop and
# start is what leaves the stack down (the "exit 143" restarts).
trap '' HUP TERM

# Dedicated "Jarvis block" — uncommon ports so other projects' dev servers never collide
BACKEND_PORT=7170
FRONTEND_PORT=7171
# 7173 (the old Claude WebSocket bridge) was retired 2026-10-18; stays reserved.

BACKEND_PID="$PID_DIR/backend.pid"
SCHEDULER_PID="$PID_DIR/scheduler.pid"
FRONTEND_PID="$PID_DIR/frontend.pid"

# --- Helpers ---

pid_alive() {  # pid_alive <pidfile>
    [ -f "$1" ] && kill -0 "$(cat "$1")" 2>/dev/null
}

# spawn <pidfile> <logname> <cmd...>
# Detaches via setsid: new session, no controlling terminal, so the service
# survives the SSH/VS Code connection dropping. Without this every service is a
# child of the login shell and dies with it (SIGTERM -> exit 143).
spawn() {
    local pidfile="$1" logname="$2"; shift 2
    # trap - HUP TERM: this script ignores those signals, and SIG_IGN survives exec,
    # so without resetting them the service would be unkillable by `dev.sh stop`.
    # 9>&- drops the inherited flock fd; without it the service holds dev.lock forever.
    (
        trap - HUP TERM
        exec setsid "$@" >>"$LOG_DIR/$logname.log" 2>&1 </dev/null 9>&-
    ) &
    echo $! > "$pidfile"
}

port_pids() {  # port_pids <port> — LISTENERS only
    # -sTCP:LISTEN is load-bearing: bare `lsof -ti:PORT` also returns every process
    # with a *client* connection to the port — including the VS Code server / sshd
    # that forwards 7171 to the remote laptop's browser. Killing that dropped the
    # whole SSH connection and every tmux client attached through it.
    lsof -ti:"$1" -sTCP:LISTEN 2>/dev/null
}

kill_tree() {  # kill_tree <pidfile> — kill children of the tracked PID, then the PID
    if [ -f "$1" ]; then
        local pid
        pid=$(cat "$1")
        pkill -P "$pid" 2>/dev/null
        kill "$pid" 2>/dev/null
        rm -f "$1"
    fi
}

kill_port() {  # kill_port <port> — fallback: kill whatever listens on the port, even if untracked
    local pids
    pids=$(port_pids "$1")
    if [ -n "$pids" ]; then
        kill $pids 2>/dev/null
        sleep 0.5
        pids=$(port_pids "$1")
        [ -n "$pids" ] && kill -9 $pids 2>/dev/null
    fi
}

find_scheduler() {  # scheduler has no port; find it by cmdline + cwd (this repo only)
    local pid
    for pid in $(pgrep -f "artisan schedule:work" 2>/dev/null); do
        if [ "$(readlink "/proc/$pid/cwd" 2>/dev/null)" = "$DIR/backend" ]; then
            echo "$pid"
        fi
    done
}

# --- Start functions (idempotent: skip if already running, refuse busy ports) ---

start_backend() {
    if pid_alive "$BACKEND_PID"; then
        echo "  Backend already running (PID $(cat "$BACKEND_PID")) — skipped"
    elif [ -n "$(port_pids $BACKEND_PORT)" ]; then
        echo "  ⚠ Port $BACKEND_PORT busy (untracked PID: $(port_pids $BACKEND_PORT | tr '\n' ' ')) — run ./dev.sh stop first"
    else
        cd "$DIR/backend"
        spawn "$BACKEND_PID" backend php artisan serve --port=$BACKEND_PORT
        echo "  Backend started (http://localhost:$BACKEND_PORT)"
    fi

    local sched
    sched=$(find_scheduler)
    if pid_alive "$SCHEDULER_PID"; then
        echo "  Scheduler already running (PID $(cat "$SCHEDULER_PID")) — skipped"
    elif [ -n "$sched" ]; then
        # Running but pid file was lost — adopt it instead of starting a duplicate
        echo "$sched" | head -1 > "$SCHEDULER_PID"
        echo "  Scheduler already running (PID $(cat "$SCHEDULER_PID"), re-adopted) — skipped"
    else
        cd "$DIR/backend"
        spawn "$SCHEDULER_PID" scheduler php artisan schedule:work
        echo "  Scheduler started (jarvis:sync-remote every 5m)"
    fi
}

start_frontend() {
    if pid_alive "$FRONTEND_PID"; then
        echo "  Frontend already running (PID $(cat "$FRONTEND_PID")) — skipped"
    elif [ -n "$(port_pids $FRONTEND_PORT)" ]; then
        echo "  ⚠ Port $FRONTEND_PORT busy (untracked PID: $(port_pids $FRONTEND_PORT | tr '\n' ' ')) — run ./dev.sh stop first"
    else
        cd "$DIR/frontend"
        # --strictPort: fail instead of silently moving to 5174 — keeps stop/status reliable
        spawn "$FRONTEND_PID" frontend npx vite --port $FRONTEND_PORT --strictPort
        echo "  Frontend started (http://localhost:$FRONTEND_PORT)"
    fi
}

# --- Stop functions (tracked PID tree first, then port fallback for anything untracked) ---

stop_backend() {
    kill_tree "$BACKEND_PID"
    kill_tree "$SCHEDULER_PID"
    local pid
    for pid in $(find_scheduler); do
        kill "$pid" 2>/dev/null
    done
    kill_port $BACKEND_PORT
    echo "  Backend stopped (+ scheduler)"
}

stop_frontend() {
    kill_tree "$FRONTEND_PID"
    kill_port $FRONTEND_PORT
    echo "  Frontend stopped"
}

# --- Status helper ---

report_status() {  # report_status <label> <pidfile> <port>
    if pid_alive "$2"; then
        echo "  $1 running (PID $(cat "$2"))"
    elif [ -n "$(port_pids "$3")" ]; then
        echo "  $1 UNTRACKED process on port $3 (PID: $(port_pids "$3" | tr '\n' ' ')) — ./dev.sh stop will clean it"
    else
        echo "  $1 stopped"
    fi
}

# --- Commands ---

case "$1" in
    start)
        echo "Starting Jarvis v2..."
        start_backend
        sleep 1
        start_frontend
        echo ""
        echo "Jarvis v2 running:"
        echo "  Backend:  http://localhost:$BACKEND_PORT  (Laravel API)"
        echo "  Frontend: http://localhost:$FRONTEND_PORT  (React UI)"
        echo ""
        echo "Open http://localhost:$FRONTEND_PORT in your browser."
        ;;
    stop)
        echo "Stopping Jarvis v2..."
        stop_frontend
        stop_backend
        echo "Done."
        ;;
    restart)
        echo "Restarting Jarvis v2..."
        stop_frontend
        stop_backend
        sleep 1
        start_backend
        sleep 1
        start_frontend
        echo ""
        echo "Jarvis v2 running:"
        echo "  Backend:  http://localhost:$BACKEND_PORT"
        echo "  Frontend: http://localhost:$FRONTEND_PORT"
        ;;
    restart-backend)
        echo "Restarting backend..."
        stop_backend
        sleep 1
        start_backend
        ;;
    restart-frontend)
        echo "Restarting frontend..."
        stop_frontend
        sleep 1
        start_frontend
        ;;
    status)
        echo "Jarvis v2 status:"
        report_status "Backend:  " "$BACKEND_PID" $BACKEND_PORT
        if pid_alive "$SCHEDULER_PID"; then
            echo "  Scheduler: running (PID $(cat "$SCHEDULER_PID"))"
        elif [ -n "$(find_scheduler)" ]; then
            echo "  Scheduler: UNTRACKED (PID: $(find_scheduler | tr '\n' ' ')) — ./dev.sh stop will clean it"
        else
            echo "  Scheduler: stopped"
        fi
        report_status "Frontend: " "$FRONTEND_PID" $FRONTEND_PORT
        ;;
    *)
        echo "Jarvis v2 - Development Server Manager"
        echo ""
        echo "Usage: ./dev.sh {command}"
        echo ""
        echo "Commands:"
        echo "  start              Start all services (idempotent — skips what's already up)"
        echo "  stop               Stop all services (tracked PIDs + port fallback)"
        echo "  restart            Restart all services"
        echo "  restart-backend    Restart Laravel API only"
        echo "  restart-frontend   Restart React frontend only"
        echo "  status             Show running services"
        echo ""
        echo "Services:"
        echo "  Backend:  http://localhost:$BACKEND_PORT  (Laravel API)"
        echo "  Frontend: http://localhost:$FRONTEND_PORT  (React UI)"
        ;;
esac
