# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-31

### Added

- `AnalysisFilter` (CI4 filter) — manual per-route attachment, enabled check,
  IP whitelist skip, non-blocking queue + background send.
- `AnalysisService` — request metadata collection (`timestamp`, `domain`,
  `path`, `method`, `srcip`, `user_agent`, `query_string`, `headers`,
  `raw_body`, `file_count`, `file_names`, `file_metadata`).
- Sensitive-field redaction (recursive, case-insensitive; default:
  `password`, `nik`, `Api-Key`, `no_telp`).
- Body truncation at `ANALYSIS_MAX_BODY_SIZE` with configurable suffix.
- File upload metadata (original name, size, MIME, extension, SHA-256 hash,
  double-extension detection) — binary content is never sent.
- Local file-based queue with `ANALYSIS_MAX_QUEUE` enforcement (oldest dropped).
- `AnalysisSend` spark command — sends queued entries, retries on failure,
  moves items to `failed/` after `ANALYSIS_MAX_RETRIES`.
- `Analysis` config class reading all `ANALYSIS_*` environment variables.
- IP/CIDR whitelist (RFC1918 + localhost by default) via `AnalysisService`.
- Multi-tenant identification from `HTTP_HOST` subdomain.
- Guzzle 7 HTTP client with configurable timeout.
- PHP 7.4 and PHP 8.4+ support.

## [Unreleased]

### Planned

- Unit tests (PHPUnit) for `AnalysisService` and `AnalysisSend`.
- Analysis Server (Docker: PHP-FPM + Nginx on Alpine) with daily gzip log
  rotation and Wazuh integration.
- k6 load test script.
- Sample CI4 application showing integration.

[1.0.0]: https://github.com/mrnaeem4/ci4-request-analysis/releases/tag/v1.0.0
