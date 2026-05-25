#!/bin/bash
set -e

# =============================================================================
# LockBits Site — Docker Entrypoint
# =============================================================================
# Waits for MySQL to be ready, ensures the database schema exists (creates
# tables if missing), then hands over to the main process (Apache).
#
# This runs on EVERY container start, making schema initialization resilient
# across deployments, volume recreations, and container restarts.
# =============================================================================

MAX_RETRIES="${DB_INIT_RETRIES:-30}"
RETRY_INTERVAL="${DB_INIT_RETRY_INTERVAL:-2}"
DB_HOST="${DB_HOST:-site-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-lockbits_client}"
DB_USER="${DB_USER:-lockbits}"
DB_PASS="${DB_PASS:-}"
SCHEMA_FILE="/var/www/html/client/database.sql"

# ── 1. Wait for MySQL (TCP port check) ────────────────────────────────────
# Uses bash built-in /dev/tcp which works with both MySQL and MariaDB clients
# and avoids SSL negotiation issues on the initial connectivity check.
echo ">>> [entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."

for i in $(seq 1 "${MAX_RETRIES}"); do
  if timeout 3 bash -c "echo > /dev/tcp/${DB_HOST}/${DB_PORT}" 2>/dev/null; then
    echo ">>> [entrypoint] MySQL port is open (attempt ${i})."
    break
  fi

  if [ "${i}" -eq "${MAX_RETRIES}" ]; then
    echo ">>> [entrypoint] ERROR: MySQL not reachable at ${DB_HOST}:${DB_PORT} after ${MAX_RETRIES} attempts."
    exit 1
  fi

  sleep "${RETRY_INTERVAL}"
done

# ── 2. Check schema file exists ────────────────────────────────────────────
if [ ! -f "${SCHEMA_FILE}" ]; then
  echo ">>> [entrypoint] WARNING: Schema file ${SCHEMA_FILE} not found. Skipping schema check."
  exec "$@"
fi

# ── 3. Check if the core `users` table exists ──────────────────────────────
TABLE_COUNT=$(mysql \
  -h "${DB_HOST}" \
  -P "${DB_PORT}" \
  -u "${DB_USER}" \
  -p"${DB_PASS}" \
  ${MYSQL_SSL_ARGS} \
  "${DB_NAME}" \
  -N \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}' AND table_name = 'users';" \
  2>/dev/null || echo "0")

TABLE_COUNT="${TABLE_COUNT//[!0-9]/}"

if [ "${TABLE_COUNT}" = "0" ]; then
  echo ">>> [entrypoint] Core tables missing. Initializing database schema from ${SCHEMA_FILE}..."
  if mysql \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u "${DB_USER}" \
    -p"${DB_PASS}" \
    ${MYSQL_SSL_ARGS} \
    "${DB_NAME}" \
    < "${SCHEMA_FILE}"
  then
    echo ">>> [entrypoint] Database schema initialized successfully."
  else
    echo ">>> [entrypoint] ERROR: Failed to initialize database schema."
    exit 1
  fi
else
  echo ">>> [entrypoint] Database schema already present."
fi

# ── 4. Hand over to the main process (Apache) ──────────────────────────────
exec "$@"
