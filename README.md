# Jarvis: a command center for AI coding agents

I build most of my software by directing AI coding agents (Claude Code) across a
dozen-plus projects at once. Jarvis is the system I built to run that work: it reads
every agent session from disk, prices the tokens it used, tracks quota, tells me which
session is waiting on me, writes recaps of what got done, and keeps the task list that
all my projects share.

It runs locally and stays up all day. This repo is a snapshot of it: the code is as
it runs, with my own project names and data taken out.

![The deck: live sessions, what needs me, and machine pressure](docs/screenshots/deck.png)

*Screenshots are from a demo database with made-up projects and generated session transcripts.*

## What it does

| Area | What happens |
|---|---|
| **Session ingestion** | Every 5 minutes a scanner reads Claude Code's JSONL transcripts, links each session (and its sub-agents) to a project by working directory, and stores messages and token usage in Postgres. |
| **Cost and quota** | Token usage per model, split by class (input, output, cache read/write), rolled up per project, per day and per account. Tracks the rolling usage window so I know how much quota is left before it resets. |
| **Live sessions** | Shows what's running right now and flags sessions that are blocked waiting for a permission prompt or my input (fed by a Claude Code hook). |
| **Recaps** | A scheduled job sorts finished sessions into real work vs. chores, then summarises the work ones by running headless `claude -p` processes concurrently. The result is a reviewable log of what each session shipped. |
| **Tasks** | One task list for every project, with dependencies, priorities, an event history, and an API that agents in other repos call to read and update their own tasks. |
| **Planning** | Daily plans, time blocks, focus sessions, interrupts, weekly reviews. |
| **Audits** | Stores findings from pre-delivery audits of deployed products, so they become tracked work instead of a one-off report. |
| **Host metrics** | Samples CPU, RAM and swap every minute. Running several agents, builds and containers at once is what actually takes the machine down. |

<table>
<tr>
<td width="50%"><img src="docs/screenshots/costs.png" alt="Quota and cost page: current 5-hour window, burn rate, per-project token share and model mix"></td>
<td width="50%"><img src="docs/screenshots/tasks.png" alt="Task board across all projects, sorted by priority and deadline"></td>
</tr>
<tr>
<td><b>Quota &amp; cost:</b> the open 5-hour window, burn rate, and which project and model is spending it.</td>
<td><b>Tasks:</b> one list across every project, sorted by priority and deadline.</td>
</tr>
</table>

## Stack

- **Backend:** Laravel 13 · PHP 8.4 · PostgreSQL 16, about 95 API routes, scheduled commands
- **Frontend:** React 19 · TypeScript · Vite · Tailwind · shadcn/ui · TanStack Query · Zustand
- **Tests:** 527 backend tests (PHPUnit, feature + unit)

## Architecture

```
~/.claude/projects/*.jsonl ──► SessionScannerService ──► Postgres (sessions schema)
                                                              │
Claude Code hooks ──► /api/sessions/notify ──────────────────►│
                                                              ▼
            scheduler: scan · recaps (claude -p) · resource samples · sync
                                                              │
                         Laravel API (services layer, thin controllers)
                                                              │
                                     React deck (Vite)  ◄─────┘
```

Conventions the code follows:
- All business logic lives in `backend/app/Services/`; controllers only validate and respond.
- Enums for every status and priority; model scopes for shared queries.
- Destructive database commands (`migrate:fresh`, `db:wipe`) refuse to run unless
  the target database name ends in `_test` (`AppServiceProvider`).

## Running it

Requires PHP 8.4 + Composer, Node 22, and PostgreSQL 16.

```bash
cd backend
cp .env.example .env          # set DB_*; CLAUDE_PROJECTS_DIR defaults to ~/.claude/projects
composer install
php artisan key:generate
php artisan migrate
php artisan test              # uses the jarvis_test database (phpunit.xml)

cd ../frontend
npm ci
npm run dev

# the UI proxies /api to :7170; point it elsewhere with JARVIS_API_URL=http://host:port

# or start API, scheduler and frontend together:
./dev.sh start                # API :7170, UI :7171
```

## Also in `tools/`

- `session-account-hook/`: a Claude Code SessionStart hook that tells the API which
  account a session runs under, so cost and quota split per account.
- `host-metrics-agent/`: a small Windows tray agent that publishes host RAM and
  commit charge, since WSL's `/proc` only sees inside the VM.

## About

Built by Rafi Irtiza Rasa: software engineer and AI-orchestration lead.
Portfolio: [rafirasa.com](https://rafirasa.com)
