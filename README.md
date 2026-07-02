# Network Monitor MVP

Symfony MVP for network monitoring with:

- MikroTik DHCP API
- MikroTik DNS cache import
- NetFlow v9 collector
- DNS-based domain enrichment
- MariaDB

The project is intentionally small. It focuses on device inventory, client linkage, traffic enrichment, and dashboard/report views.

## Stack

- Symfony 6.4
- Doctrine ORM and Migrations
- Twig
- MariaDB/MySQL
- nginx + PHP-FPM
- Docker Compose

## Quick Start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction
```

Open:

- UI: http://localhost:8080

## Environment

Set MikroTik values in `.env`:

```dotenv
MIKROTIK_HOST=192.168.88.1
MIKROTIK_PORT=8728
MIKROTIK_USER=api
MIKROTIK_PASSWORD=secret
```

`APP_SECRET`, admin credentials, and MikroTik credentials must be overridden for production.

## Useful Commands

```bash
docker compose exec php php bin/console app:sync-mikrotik-leases
docker compose exec php php bin/console app:sync-mikrotik-dns-cache
docker compose exec php php bin/console app:netflow:listen --host=0.0.0.0 --port=2055
docker compose exec php php bin/console app:enrich-network-flows --limit=20000
docker compose exec php php bin/console app:aggregate-flows
docker compose exec php php bin/console app:cleanup-flows --days=90
```

## Scheduler / Cron

```cron
*/2 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:sync-mikrotik-leases
*/2 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:sync-mikrotik-dns-cache
*/5 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:enrich-network-flows --limit=20000
*/1 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:netflow:listen --host=0.0.0.0 --port=2055
0 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:aggregate-flows
30 2 * * * cd /path/to/project && docker compose exec -T php php bin/console app:cleanup-flows --days=90
```

## MikroTik Setup

DHCP leases are synchronized via MikroTik RouterOS API.

DNS cache is imported from MikroTik and used for approximate domain attribution.

NetFlow v9 export should target the host running the `netflow-worker` container:

```routeros
/ip traffic-flow set enabled=yes interfaces=all
/ip traffic-flow target add dst-address=<SERVER_IP> port=2055 version=9
```

DNS matching is approximate:

- MikroTik DNS cache does not know which client requested a domain
- `network_flow.domain` is matched by `resolved_ip = external_ip`
- This is not 100% accurate and not DPI

## Web UI

Routes:

- `/`
- `/dashboard`
- `/devices`
- `/devices/{id}`
- `/clients`
- `/clients/{id}`
- `/reports`

Dashboard and detail pages show:

- total devices
- total clients
- traffic today
- top devices
- top clients
- top domains
- top apps
- recent flows/activity

## Diagnostics

```sql
SELECT COUNT(*) FROM device;
SELECT COUNT(*) FROM client;
SELECT COUNT(*) FROM network_flow;
SELECT COUNT(*) FROM dns_cache_record;
SELECT COUNT(*) FROM network_flow WHERE domain IS NOT NULL;
SELECT COUNT(*) FROM network_flow WHERE app_name IS NOT NULL;
SELECT app_name, SUM(bytes) FROM network_flow GROUP BY app_name ORDER BY SUM(bytes) DESC LIMIT 10;
SELECT domain, SUM(bytes) FROM network_flow WHERE domain IS NOT NULL GROUP BY domain ORDER BY SUM(bytes) DESC LIMIT 10;
SELECT direction, COUNT(*), SUM(bytes) FROM network_flow GROUP BY direction;
```

## Notes

- `network_flow` enrichment is intentionally approximate.
- Passwords and secrets must be provided via environment variables.
- `app:cleanup-flows` removes old `network_flow` rows.
