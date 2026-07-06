# Performance Refactor Plan

Goal: keep the UI dynamic, but stop paying the full `network_flow` scan cost on every request or cache warm-up.

Related audit:

- [SQL_AUDIT.md](/home/alexgold/DEV/monitoring_servis_kpsi/SQL_AUDIT.md)

## Progress

- [x] SQL audit completed and documented in `SQL_AUDIT.md`
- [ ] Measure the red queries with `EXPLAIN`
- [x] Replace raw-flow dashboard reads with rollups
- [x] Replace raw-flow page cache reads with rollups
- [x] Make aggregation incremental

## Current DB Hotspots

These are the places that currently do the expensive work:

- `src/Service/DashboardCacheService.php`
  - multiple `GROUP BY` queries over `network_flow`
  - top devices, top clients, top apps, top domains, traffic totals
- `src/Service/PageCacheService.php`
  - client/device detail caches are built from raw flow data
  - recent domains, top apps, recent activity are all aggregated from `network_flow`
- `src/Controller/AppController.php`
  - several report/detail queries still read `network_flow` directly
- `src/Service/TrafficAggregator.php`
  - already contains the first reusable rollup path (`DeviceDailyUsage`)

## What the code already tells us

- The project already has useful rollup tables:
  - `device_daily_usage`
  - `traffic_snapshot`
  - `dns_cache_record`
- The main problem is not missing indexes only.
- The main problem is repeated aggregation of the same raw rows in request-time code and in warm-up commands.
- There are already several indexes on `network_flow`, so adding more blindly is unlikely to solve the 7+ minute warm-up.

## Step By Step Plan

### 1. Leave `network_flow` only as a raw event stream

Status: pending

Checklist:

- [ ] Stop treating `network_flow` as the main read model.
- [ ] Keep it only for ingestion, enrichment, and short recent-history lookups.
- [ ] Define a retention policy for how long raw rows are kept.

Self-check:

- A page load should not need a full scan of `network_flow`.
- A cache warm-up should not need to recompute everything from zero.

### 2. Create dedicated read-optimized aggregate tables

Status: in progress

Target data:

- [x] daily totals per device
- [x] daily totals per client
- [ ] hourly totals
- [x] top apps per day
- [x] top domains per day
- [ ] recent activity as a limited indexed slice

Likely table direction:

- [x] `device_daily_usage`
- [x] `device_daily_app_usage`
- [x] `device_daily_domain_usage`
- [x] `traffic_daily_direction_usage`
- [x] `traffic_aggregation_state`
- [ ] `client_daily_usage`
- [ ] `traffic_hourly_usage`
- [ ] `recent_activity_feed`

Self-check:

- Each table should answer one UI query class cheaply.
- No UI page should need to assemble these from raw flows at request time.

### 3. Update aggregates incrementally only for new rows

Status: in progress

Checklist:

- [x] Track the last processed `network_flow.id` or timestamp.
- [x] Aggregate only rows newer than that marker.
- [x] Make the job idempotent.
- [ ] Allow a full rebuild only as a maintenance task.

Self-check:

- The job runtime should scale with new traffic volume, not total historical volume.
- Re-running the job should not duplicate totals.

### 4. Make web requests read aggregate tables, not `network_flow`

Status: in progress

Checklist:

- [x] Dashboard reads from rollups.
- [x] Client list reads from rollups.
- [x] Device list reads from rollups.
- [ ] Detail pages use rollups first, raw data only for latest N events.

Self-check:

- `GET /dashboard` should stay fast even when raw flow volume grows.
- `GET /clients` should not trigger a recomputation of all client stats.
- `GET /devices` should not do a full scan of flow history.

### 5. Keep raw `network_flow` only for recent detail activity

Status: pending

Checklist:

- [ ] Use raw flows only for the latest activity window.
- [ ] Keep the number of rows bounded, e.g. last 20 or last 100.
- [ ] Add or reuse indexes that match the exact recent-activity filters.

Self-check:

- Detail pages should still feel live.
- The raw table should not be used for long-range summaries.

## Query Optimization Notes

Before adding more indexes, check the actual access patterns:

- `DashboardCacheService` currently does:
  - `SUM(bytes)` over a time range
  - `GROUP BY device_id`
  - `GROUP BY client_id`
  - `GROUP BY app_name`
  - `GROUP BY domain`
- `PageCacheService` currently does similar grouping for client/device detail pages.
- `AppController` still contains several raw queries for reports and detail pages.

Optimization order:

1. Replace repeated scans with rollups.
2. Add only indexes that match the remaining raw queries.
3. Keep raw queries bounded by time and row count.
4. Validate with `EXPLAIN` before adding more indexes.

## Suggested Implementation Order

1. [ ] Add the new aggregate table(s) and migration(s).
2. [ ] Add an incremental aggregation job.
3. [ ] Switch dashboard reads to the aggregate layer.
4. [ ] Switch client/device pages to the aggregate layer.
5. [ ] Keep only recent activity on raw `network_flow`.
6. [ ] Add or adjust indexes only after measuring the new query plan.

## Success Criteria

- Dashboard loads quickly without warm-up blocking requests.
- Client and device pages do not rebuild large caches on demand.
- Background jobs scale with new data, not with full history.
- Raw `network_flow` remains the source of truth, but not the main read model.
