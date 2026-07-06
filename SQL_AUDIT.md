# SQL Audit

Scope: identify the queries that are making the project heavy, with emphasis on request-time code and cache warm-up paths.

## Executive Summary

The main slowdown is not one missing index. It is repeated aggregation over `network_flow` in several places:

- dashboard cache warm-up
- client/device page caches
- detail pages for clients/devices
- reports that still hit raw flow rows

The project already has some rollup structures (`device_daily_usage`, `traffic_snapshot`), but most UI paths still use raw `network_flow`.

## Severity Legend

- `RED` = likely to block requests or warm-up jobs at scale
- `YELLOW` = expensive but bounded, should be refactored soon
- `GREEN` = low-cost or already acceptable

## RED Queries

### 1. Dashboard cache rebuild

File:

- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L43)

Queries:

- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L134)
- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L142)
- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L168)
- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L197)
- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L227)
- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L258)

Pattern:

- multiple full-range aggregates over `network_flow`
- `SUM(bytes)`
- `SUM(CASE WHEN direction = ...)`
- `GROUP BY device_id`, `GROUP BY client_id`, `GROUP BY app_name`, `GROUP BY domain`
- top-N queries using `ORDER BY totalBytes DESC`

Why it is heavy:

- the same raw table is scanned several times in one warm-up
- each query may walk a large time window
- top-N queries still need full aggregation before sorting

Optimization direction:

- move these reads to aggregate tables
- keep raw `network_flow` only for recent activity and rebuild jobs
- if raw access remains, validate each query with `EXPLAIN`

### 2. Client page cache rebuild

File:

- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L111)

Queries:

- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L392)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L419)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L438)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L500)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L537)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L570)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L775)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L810)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L837)

Pattern:

- `usageStatsByDeviceFromFlows()` and `usageTotalsByDeviceFromFlows()` scan `network_flow` by day range
- `topDomainsByDeviceFromFlows()` uses a `LIMIT 50000` subquery then groups again
- `recentDomainsByDevice()` uses a `LIMIT 20000` subquery then groups again
- `topAppsByDevice()` uses `LIMIT 50000` then groups again
- `recentActivityByDevice()` reads the latest 5000 rows from `network_flow`

Why it is heavy:

- these methods are called while building cached client/device pages
- they duplicate the same work for device rows and client rows
- `recentDomainsByDevice()` and `topAppsByDevice()` still depend on raw flow history instead of a prepared rollup

Optimization direction:

- build `device_daily_usage` and a client aggregate table incrementally
- move `recentDomains`, `topApps`, and `recentActivity` to bounded summary tables
- keep `recentActivity` limited to a short window and indexed by `received_at DESC`

### 3. Client daily usage fallback

File:

- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L885)

Query:

- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L904)

Pattern:

- `GROUP BY DATE(received_at)` over `network_flow`
- filtered by client/device identifiers

Why it is heavy:

- `DATE(received_at)` reduces index usefulness
- this path becomes expensive if a client has many linked devices or if the raw table is large

Optimization direction:

- prefer rollups from `device_daily_usage`
- only hit `network_flow` for missing or very recent data

### 4. Warm page cache uses raw flows too

File:

- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L55)

Problem:

- `refreshClients()` and `refreshDevices()` each rebuild the whole detail payload
- the rebuild uses the same raw flow scans listed above

Optimization direction:

- rebuild from aggregates only
- make `refresh` incremental, not full-history

## YELLOW Queries

### 5. Report rows

File:

- [src/Controller/AppController.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Controller/AppController.php#L1001)

Query:

- [src/Controller/AppController.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Controller/AppController.php#L1003)

Pattern:

- report totals are already based on `DeviceDailyUsage`

Why it is acceptable:

- this is a rollup table, not raw flow data
- query is comparatively cheap

Optimization direction:

- keep it as-is
- if needed, add indexes on `device_daily_usage(device_id, date)` and `device_daily_usage(date)` only if the planner shows a need

### 6. `clientFlows()` detail lookup

File:

- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L939)

Pattern:

- first query gets only flow ids from `network_flow`
- second query fetches entities by those ids

Why it is acceptable:

- bounded by `LIMIT`
- good for recent activity/drill-down

Optimization direction:

- keep bounded
- if it remains hot, consider returning only a small raw DTO instead of full entity hydration

### 7. Device/client lookups by repository

Files:

- [src/Controller/ApiController.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Controller/ApiController.php#L35)
- [src/Controller/ApiController.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Controller/ApiController.php#L53)

Pattern:

- `findAll()` on `client` and `device`

Why it is acceptable for now:

- these tables are small compared to `network_flow`

Optimization direction:

- not a priority
- can be revisited only if the entity counts grow significantly

## GREEN Queries

### 8. `latestDevices`

Files:

- [src/Service/DashboardCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/DashboardCacheService.php#L297)
- [src/Service/PageCacheService.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Service/PageCacheService.php#L325)

Reason:

- reads from `device`, not `network_flow`
- bounded with `LIMIT`

### 9. `DeviceDailyUsage` report query

File:

- [src/Controller/ApiController.php](/home/alexgold/DEV/monitoring_servis_kpsi/src/Controller/ApiController.php#L118)

Reason:

- uses a rollup table
- this is the right model for reporting

## Immediate Conclusions

1. Adding more indexes to `network_flow` alone is not the right first move.
2. The heavy part is the repeated `GROUP BY` work over raw flows.
3. The next refactor should move read paths to rollup tables and incremental aggregation.
4. `recentActivity` can stay raw, but only as a bounded, indexed window.

