# phpdot/scheduler

Distributed cron-style scheduler for the PHPdot ecosystem. Identical servers all
tick; an atomic claim in the shared store guarantees exactly one of them runs
each task. Long tasks hold a lease they keep renewing; when a runner dies, its
fire is adopted by another server (attempt +1) the next time any server claims
for that schedule — never a catch-up flood. A fencing token keeps a zombie's
writes out of the record, and a heartbeat the store refuses stops the execution
that lost it. Every adoption appends the abandoned attempt to the run's
`attempts` log. Every fire is history you can query.

Delivery is **at-least-once**: a run whose final write fails or whose lease was
lost may execute again, and a fire is abandoned at most `maxAttempts` times
(three by default) before it is recorded failed. Write commands idempotently —
most update-style operations already are.

Two things the guarantee does not cover. A worker that cannot reach the store
keeps its command running until its own lease measurement expires, so a job
adopted at lease expiry can overlap it for about one heartbeat interval. And a
run stopped by a host shutdown is recorded failed: it is not retried, and
`scheduler:monitor` does not count it as a failure.

Plain PHP end to end — no Swoole, no per-task cron entries. Storage-agnostic by
contract (`SchedulerStoreInterface`), relational first via phpdot/database
(MySQL, PostgreSQL, SQLite). Commands execute through symfony/process under a
mandatory timeout that stops the whole process tree, not only the shell.
Stopping a command needs no PHP extension: the tree is read from `/proc` on
Linux and through `pgrep` and `ps` elsewhere. Where a host can list no
processes only the shell is stopped, and the run's record says so.

## How it works

1. Every server runs the same ticker: cron calls `scheduler:tick` every minute,
   or `scheduler:work` runs the same pass as a daemon (sub-minute schedules).
2. Each pass reads the due schedules from the store and tries to **claim** each
   fire: one guarded statement against a unique `(schedule_id, tick)` row,
   inside a transaction that also enforces the overlap policy and the host
   concurrency cap.
3. Exactly one server's claim wins. The winner executes the task and renews its
   lease (heartbeat) while it runs; everyone else records nothing and exits.
4. If the winner dies, its lease expires and the next claim for that schedule
   **adopts** the run (attempt +1), whatever fire it held. Past the attempt
   ceiling the fire is recorded failed and the claim takes the fire that is
   due. A paused process that comes back later is **fenced**: its writes carry
   a stale token and touch nothing, and the heartbeat it is refused stops its
   command.
5. Every fire leaves a run record: state, holder, duration, exit code, output
   tail — the observability layer, not an afterthought.

All clock arithmetic runs on the **store's own clock in UTC**, so fleet clock
skew cannot break a lease. A store that fails for one schedule costs that
schedule alone the pass: it is reported as `store_error`, and the schedules
after it still run.

## The commands

| Command | Job |
|---|---|
| `scheduler:install` | Create or upgrade the scheduler tables and their indexes (idempotent) |
| `scheduler:define` | Create or update a schedule: `scheduler:define reports:generate 'dot reports:generate' --every=1m` |
| `scheduler:sync` | Reconcile the catalog of `#[Scheduled]` commands — the interface's menu of what *can* run |
| `scheduler:list` | Every schedule and when it next fires |
| `scheduler:tick` | One pass — what cron calls every minute on every server |
| `scheduler:work` | The same pass as a daemon (`--interval`, signals handled, live tasks stopped on exit) |
| `scheduler:runs` | Run history, newest first (`--schedule`, `--limit`) |
| `scheduler:monitor` | Health check for pagers: exit 1 on a missed fire, a stuck run, or any failed run inside the window |
| `scheduler:trigger` | Fire one schedule now, claim-guarded; exits non-zero when the run failed or the claim was refused |
| `scheduler:pause` / `scheduler:resume` | Toggle without deleting |
| `scheduler:remove` | Delete a definition; run history is kept |
| `scheduler:prune` | Close died fires as expired, delete terminal runs that started more than `--days` ago, in batches |

A failure counts whichever run it belongs to, not only the newest. A schedule
owes nothing until the first fire after it was defined, redefined or resumed;
`scheduler:monitor` reports it as `pending` until then.

Patterns are cron expressions (`--cron '*/5 * * * *'`, `@daily`) or intervals
(`--every=30s`, `--every=5 minutes`) — two first-class kinds, never converted
into each other — or a named preset: `--preset=every-minute`, `every-5-minutes`,
`hourly`, `daily-midnight`. Presets are a menu over pattern strings, not a
second source of truth: the preset expands at define time and the schedule owns
the resulting expression forever (`daily-midnight` staggers its minute by a
hash of the schedule id, so a fleet of midnight jobs doesn't stampede). Per-
schedule policies: `--timeout` (mandatory, default 300s), `--idle-timeout`,
`--overlap forbid|allow`, `--grace` (how late a missed fire may still be
claimed — never a catch-up flood), `--timezone` (cron fields are interpreted
in it).

## Timezones, collisions, and DST

- **UTC by default, everywhere it matters**: tick ids, leases, and history are
  canonical UTC instants; a schedule's timezone only interprets its cron fields
  and converts to UTC before anything is stored.
- **One logical job = one schedule id.** Two schedules whose timings resolve to
  the same instant both fire — `forbid` guards per schedule, not per command.
  `scheduler:define` warns when a new schedule's next fires coincide with
  another enabled schedule's.
- **DST**: a cron in a DST-shifting zone double-runs or skips on transition
  days (intervals are immune). Prefer UTC or intervals unless the business
  genuinely means local time.

## What a command is told

Every command runs with three variables naming its fire, so an adopted run can
act on the tick it revives instead of the moment it happens to run:

| Variable | Value |
|---|---|
| `SCHEDULER_SCHEDULE` | The schedule id |
| `SCHEDULER_TICK` | The fire, as its UTC tick identifier |
| `SCHEDULER_ATTEMPT` | Which attempt at that fire this run is |

The schedule sets them and they override any same-named entry of the configured
environment.

## The attempt ceiling and housekeeping

`RelationalStore` takes `maxAttempts` (three by default): how many times one
fire may be handed to a runner before the store records it failed instead of
adopting it again.

Re-applying an unchanged schedule writes nothing, so a schedule defined at
every deploy does not reset when it became expected to fire. Retention
(`--days`, never less than one) is measured from each run's start. The indexes
the claim and the monitor read are created by `scheduler:install` on an existing
store without moving the schema stamp, so servers on the previous release keep
working beside it.

## Trust boundary

The schedules table **is** shell: every `command` string runs through the
worker, and scheduled commands inherit the worker's environment. Write access
to the store is therefore code execution on every server that ticks — treat it
with the same care as deploy credentials. `scheduler:define` is the only
intended write path.

Developers mark what *can* be scheduled; the interface decides *when* — nothing
about timing is hardcoded:

```php
#[AsCommand(name: 'reports:generate', description: 'Build the nightly reports')]
#[Scheduled(name: 'reports:generate')]
final class GenerateReportsCommand extends Command { … }
```

`scheduler:sync` mirrors every `#[Scheduled]` command into the **catalog**
(name, command, description — reconciled as code changes). The user then turns
a catalog entry into a schedule with only a timing:

```bash
scheduler:define reports:generate --every=1m
```

Patterns, policies, pause, and removal all live in the store, changed at
runtime, never by a deploy.

## Requirements

| Requirement | Constraint |
|---|---|
| PHP | `>= 8.5` |
| `ext-mbstring` | `*` |
| `dragonmantank/cron-expression` | `^3.0` |
| `phpdot/console` | `^0.7` |
| `phpdot/database` | `^0.7` |
| `psr/clock` | `^1.0` |
| `symfony/console` | `^8.0` |
| `symfony/process` | `^8.0` |

`phpdot/container` is a dev-only suggestion — the `#[Config('scheduler')]`
attribute on `SchedulerConfig` is inert until a phpdot application reflects it.
`ext-pcntl` is suggested for `scheduler:work` signal handling.

## Installation

```bash
composer require phpdot/scheduler
```

## Status

Released with the monorepo under its one version number. See the monorepo
README for the release line.

## License

MIT — see [LICENSE](LICENSE).
