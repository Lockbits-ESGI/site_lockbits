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
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"
SCHEMA_FILE="/var/www/html/client/database.sql"

echo ">>> [entrypoint] Connecting to MySQL at ${DB_HOST}:${DB_PORT} as user '${DB_USER}', database '${DB_NAME}'..."

# ── 0. Use MYSQL_PWD to avoid argument parsing edge cases ──────────────────
# We use MYSQL_PWD instead of -p"${DB_PASS}" because:
#   - When DB_PASS is empty, -p"" can cause MariaDB client to read password
#     from stdin (the SQL file), which corrupts the import.
#   - MYSQL_PWD is consistent across MySQL and MariaDB clients.
export MYSQL_PWD="${DB_PASS}"

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

# ── 2. Provision database and grants via root ─────────────────────────────
# When DB_ROOT_PASSWORD is set, we connect as root to ensure the database
# exists and the application user has ALL PRIVILEGES on it. This is needed
# because the application user (DB_USER) may lack CREATE / ALTER / DROP
# privileges on the MySQL server.
if [ -n "${DB_ROOT_PASSWORD}" ]; then
  echo ">>> [entrypoint] Provisioning database '${DB_NAME}' and granting access to '${DB_USER}'@'%'..."
  if MYSQL_PWD="${DB_ROOT_PASSWORD}" mysql \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u root \
    --skip-ssl \
    -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1
  then
    echo ">>> [entrypoint] Database '${DB_NAME}' ensured."
  else
    echo ">>> [entrypoint] ERROR: Could not create database '${DB_NAME}' as root."
    exit 1
  fi

  if MYSQL_PWD="${DB_ROOT_PASSWORD}" mysql \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u root \
    --skip-ssl \
    -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;" 2>&1
  then
    echo ">>> [entrypoint] Grants for '${DB_USER}'@'%' ensured."
  else
    echo ">>> [entrypoint] ERROR: Could not grant privileges to '${DB_USER}'@'%'."
    exit 1
  fi
fi

# ── 3. Check schema file exists ────────────────────────────────────────────
if [ ! -f "${SCHEMA_FILE}" ]; then
  echo ">>> [entrypoint] WARNING: Schema file ${SCHEMA_FILE} not found. Skipping schema check."
  exec "$@"
fi

# ── 4. Test actual MySQL connection ────────────────────────────────────────
echo ">>> [entrypoint] Testing MySQL connection..."
if ! mysql \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u "${DB_USER}" \
    --skip-ssl \
    -N \
    -e "SELECT 1" > /dev/null 2>&1
then
  echo ">>> [entrypoint] ERROR: Can not connect to MySQL as user '${DB_USER}'. Check DB_USER and DB_PASS."
  exit 1
fi
echo ">>> [entrypoint] MySQL connection OK."

# ── 5. Check if the core `users` table exists ──────────────────────────────
echo ">>> [entrypoint] Checking if schema already exists..."
TABLE_COUNT=$(mysql \
  -h "${DB_HOST}" \
  -P "${DB_PORT}" \
  -u "${DB_USER}" \
  --skip-ssl \
  -N \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}' AND table_name = 'users';" \
  2>&1 || true)

TABLE_COUNT="${TABLE_COUNT//[!0-9]/}"

if [ "${TABLE_COUNT}" = "0" ]; then
  echo ">>> [entrypoint] Core tables missing. Initializing database schema from ${SCHEMA_FILE}..."
  if mysql \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u "${DB_USER}" \
    --skip-ssl \
    "${DB_NAME}" \
    < "${SCHEMA_FILE}" 2>&1
  then
    echo ">>> [entrypoint] Database schema initialized successfully."
  else
    echo ">>> [entrypoint] ERROR: Failed to initialize database schema."
    exit 1
  fi
else
  echo ">>> [entrypoint] Database schema already present."
fi

# ── 6. Hand over to the main process (Apache) ──────────────────────────────
exec "$@"
