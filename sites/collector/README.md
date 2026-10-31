# collector.tritonwrestling.com

Serves `collector.js` and hosts the ingestion endpoint (`/log`) that writes
collected data into the database. Deploys to `/var/www/collector.tritonwrestling.com`.

Populated starting HW3. Collector gathers three data categories — **static**,
**performance**, **activity** — and POSTs them (suggested transport:
`navigator.sendBeacon`) to `/log`, which persists them to MySQL/PostgreSQL.
