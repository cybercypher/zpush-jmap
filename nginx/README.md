# Docker / Nginx Configuration

This directory contains the Docker build and runtime configuration for the Z-Push Stalwart backend.

## Files

| File | Purpose |
|------|---------|
| `Dockerfile` | Multi-stage build: Rust extension + Debian/PHP-FPM/Nginx |
| `docker-compose.yml` | Service definition with environment variables and volumes |
| `entrypoint.sh` | Container init: config injection, PHP/Nginx tuning, service startup |
| `nginx-zpush.conf` | Nginx routing (ActiveSync, Autodiscover, healthcheck) |
| `manage.sh` | CLI for container lifecycle (build, up, down, logs, etc.) |
| `env.example` | Environment variable template |

## Quick Reference

```bash
./manage.sh --init          # Create .env and data directories
./manage.sh --up            # Build and start
./manage.sh --up-no-build   # Start/recreate using existing image tag
./manage.sh --restart       # Stop, rebuild, and start
./manage.sh --restart-no-build # Stop and start without rebuild
./manage.sh --down          # Stop
./manage.sh --logs          # Tail logs
./manage.sh --health        # Check /healthz
./manage.sh --backend-version # Show configured/running backend release ID
./manage.sh --zpush-version # Show pinned/running Z-Push version
./manage.sh --console       # Shell into container
./manage.sh --backup        # Backup persistent data
./manage.sh --set-port 9080 # Change listen port
./manage.sh --set-image local/zpush:2026-02-14-push1
./manage.sh --export-image /tmp/zpush.tar.gz
./manage.sh --import-image /tmp/zpush.tar.gz
```

## Persistent Data

Stored in `./zpush/` (created by `--init`):

- `config/` — Z-Push configuration (auto-generated on first run)
- `state/` — Device sync state
- `log/` — Application logs

See the [main README](../README.md) for full documentation.

## Privilege Model

This image intentionally does not set a `USER` directive in `nginx/Dockerfile`.

- The Nginx master process starts as root so it can bind privileged ports (for example `443`) and manage worker processes.
- Nginx workers and PHP-FPM workers run as `www-data`.
- Runtime application/state paths are owned by `www-data` (`/var/www/zpush`, `/data`).

If you only deploy on unprivileged ports, you can run with additional runtime hardening, but that is not the default model for this project.
