# Network Monitor MVP

Symfony MVP for inventorying network devices by MAC address and viewing client activity from MikroTik DHCP leases and ntopng traffic data. Grafana, billing, captive portal, router-side registration and access control are intentionally out of scope.

## Stack

- Symfony 6.4, Twig, Doctrine ORM, Migrations
- MariaDB/MySQL
- nginx + PHP-FPM
- ntopng
- Chart.js
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

- Web UI: http://localhost:8080
- ntopng: http://localhost:3000

Default admin credentials from `.env`:

- user: `admin`
- password: `admin123`

Change `ADMIN_USER`, `ADMIN_PASSWORD`, and `APP_SECRET` before production use.

## Mock Mode

Mock mode is enabled by default:

```dotenv
MOCK_NETWORK_DATA=true
```

It provides demo clients, devices, traffic snapshots, apps and domains. The service works without real MikroTik or ntopng data.

Useful commands:

```bash
docker compose exec php php bin/console app:sync-mikrotik-leases
docker compose exec php php bin/console app:sync-ntopng-traffic
docker compose exec php php bin/console app:aggregate-daily-usage
docker compose exec php php bin/console app:enrich-site-catalog
docker compose exec php php bin/console app:cleanup-traffic-snapshots
```

## Scheduler / Cron

Example host crontab:

```cron
*/2 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:sync-mikrotik-leases
*/2 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:sync-ntopng-traffic
0 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:aggregate-daily-usage
10 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:enrich-site-catalog
30 2 * * * cd /path/to/project && docker compose exec -T php php bin/console app:cleanup-traffic-snapshots
```

## MikroTik API

Set in `.env`:

```dotenv
MIKROTIK_HOST=192.168.88.1
MIKROTIK_PORT=8728
MIKROTIK_USER=api
MIKROTIK_PASSWORD=secret
```

Enable API on MikroTik and create a read-only API user. The MVP has a safe integration boundary: if MikroTik is unavailable, the command logs the error and the UI keeps showing the last saved data. It does not write comments or any other data back to MikroTik.

## ntopng

Set in `.env`:

```dotenv
NTOPNG_URL=http://ntopng:3000
NTOPNG_USER=admin
NTOPNG_PASSWORD=admin
```

If ntopng is unavailable, sync errors are logged and the UI keeps showing stored snapshots and aggregates.

## MikroTik Traffic Flow / NetFlow

Configure MikroTik to export traffic flow to the server running ntopng:

```routeros
/ip traffic-flow set enabled=yes interfaces=all
/ip traffic-flow target add dst-address=<SERVER_IP> port=2055 version=9
```

The Compose file exposes UDP `2055` for NetFlow and ntopng Web UI on port `3000`.

## Web UI

Routes:

- `/dashboard`
- `/clients`
- `/clients/{id}`
- `/devices`
- `/devices/{id}`
- `/reports`

Features:

- View devices by MAC, IP, hostname, vendor, VLAN and activity
- Link a MAC/device to an existing or new client
- Client detail pages with devices, snapshots and charts
- Device detail pages with IP history, snapshots and 30-day usage chart
- Top apps and domains with favicon URLs
- Daily and monthly reports with CSV export

## API

- `GET /api/dashboard`
- `GET /api/clients`
- `GET /api/clients/{id}`
- `GET /api/devices`
- `GET /api/devices/{id}`
- `POST /api/devices/{id}/link-client`
- `GET /api/reports/daily`
- `GET /api/reports/monthly`

Example link request:

```bash
curl -X POST http://localhost:8080/api/devices/1/link-client \
  -H 'Content-Type: application/json' \
  -d '{"fullName":"Ivan Petrenko","roomNumber":"203","phone":"+380501112233"}'
```

## Notes

- Primary device identifier is MAC address.
- Passwords and integration credentials are read only from environment variables.
- Traffic snapshots are intentionally short-lived and can be cleaned by `app:cleanup-traffic-snapshots`.
- Daily aggregates are stored in `device_daily_usage` for fast dashboard and report queries.
