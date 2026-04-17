# Security and Operational Hardening

This document summarizes the engineering and operational hardening work in `zpush-jmap`, and why it is suitable for enterprise-style ActiveSync deployments when operated with standard network controls.

## Goals

- Provide production-safe ActiveSync connectivity to Stalwart via JMAP.
- Keep compatibility with existing mobile clients.
- Preserve operational predictability (repeatable builds, rollback, state safety).
- Reduce common deployment and implementation security risks.

## Hardening Layers

### 1) Network Boundary and Exposure Control

- Backend runs with `network_mode: host` to keep host firewall policy authoritative.
- Listener address is explicit (`ZPUSH_BIND_ADDRESS`), with secure default loopback binding.
- NGINX endpoint exposure is deny-by-default:
  - only `/Microsoft-Server-ActiveSync`
  - only `/autodiscover/Autodiscover.xml`
  - explicit `/healthz`
- `server_tokens off;` is enabled to reduce passive fingerprinting.

### 2) TLS and Transport Safety

- Front-end TLS termination model is documented and expected for internet-facing deployments.
- Runtime settings enforce strong defaults for Stalwart upstream transport:
  - `STALWART_SSL_VERIFYPEER=true`
  - `STALWART_SSL_VERIFYHOST=true`
  - `STALWART_ALLOW_INSECURE_HTTP=false`
- The Rust client includes scheme safety checks to prevent HTTPS-to-HTTP downgrade paths where not explicitly allowed.

### 3) Configuration Injection Safety

- `nginx/entrypoint.sh` validates booleans, integers, enums, bind addresses, and size values before applying them.
- Invalid values fail startup early instead of producing unsafe runtime configuration.
- Backend config injection uses validated/sanitized literals (no raw code interpolation).

### 4) Credential and Secret Handling

- Rust-side password storage uses `Zeroizing<String>`.
- PHP path clears sensitive strings (`sodium_memzero()` when available + overwrite fallback).
- Runtime environment keys used for Rust options are cleared on setup/logoff transitions.

### 5) Device Admission and Access Policy (ABQ)

- Built-in ABQ policy supports allow/block/quarantine rules by:
  - user-agent (`ua:`)
  - device type (`dt:`)
  - device id (`id:`)
  - any field (`any:`)
- ABQ regex handling is hardened with:
  - regex pattern length cap
  - process-local regex cache
  - cache diagnostics logging

### 6) Availability-Oriented Query Control

- Large mailbox/card/calendar ID queries are paged in controlled windows.
- Query caps and warning logs prevent unbounded high-memory fetch behavior.
- This improves stability for large tenants without changing protocol behavior for clients.

### 7) Build and Supply-Chain Integrity

- Composer binary is sourced from the official `composer:2` image.
- Z-Push source tarball is pinned to a specific release tag.
- Z-Push tarball checksum is verified during build.
- Build inputs are now explicit and reproducible for change-control workflows.

### 8) Operational Safety and Lifecycle

- `manage.sh` provides repeatable commands for:
  - build/start/stop
  - health checks
  - logs
  - backup
  - image export/import
- Recommended deployment pattern supports lab build + production image promotion.
- Runbook includes non-destructive rollout guidance and rollback procedure.

### 9) Release Identification and Runtime Traceability

- `STALWART_BACKEND_VERSION` is configurable in `.env` and injected into backend runtime config.
- Authentication logs include the backend release identifier.
- `./manage.sh --backend-version` reports:
  - configured release ID from `.env`
  - runtime release ID from active container config

This supports quick verification of "what is actually running" during maintenance and incident response.

## Enterprise-Grade Operational Characteristics

The project includes operational controls typically expected in enterprise messaging environments:

- Explicit network segmentation and trusted-range-only backend exposure.
- Deterministic deployment and rollback workflow.
- Stateful mobile sync preservation across upgrades (`zpush/state` handling).
- Device policy controls for admission/deny patterns.
- Hardened defaults with documented lab-only exceptions.
- Runtime version traceability for audit/change tickets.

## Operator Responsibilities (Still Required)

No software can replace baseline operations hygiene. Production operators must still:

- Keep host firewall policy in place and limit source ranges.
- Keep public/mobile traffic on front-end TLS endpoints only.
- Keep production `.env` TLS verification settings enabled for non-loopback upstreams.
- Use change control for image/tag promotion and rollback.
- Protect build and artifact distribution paths.

## Related Documents

- Executive overview: `docs/EXECUTIVE_SUMMARY.md`
- Production runbook: `docs/PRODUCTION_DEPLOYMENT.md`
- Security audit status: `docs/SECURITY_AUDIT.md`
