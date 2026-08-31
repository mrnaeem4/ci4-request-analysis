# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-08-31

### Changed

- **Architecture overhaul**: logs are no longer sent to an external Analysis
  Server. The filter now writes directly to the CodeIgniter `writable/logs`
  directory as JSON Lines.
- Configuration renamed from `ANALYSIS_*` to `REQUEST_LOG_*`; config class
  `Analysis` → `RequestLog`.
- Filter renamed `AnalysisFilter` → `RequestLogFilter` (alias `requestlog`).
- Service renamed `AnalysisService` → `RequestLogService`.
- Single log file `analysis.log` with automatic daily rotation + gzip
  compression + retention pruning.

### Removed

- `AnalysisSend` command, local queue, and `failed/` retry handling.
- Guzzle dependency and `ANALYSIS_SERVER_URL` / `ANALYSIS_API_KEY` config.

### Added

- `requestlog:rotate` spark command — manual/cron fallback for rotation,
  gzip compression and pruning of old logs.
- `REQUEST_LOG_RETENTION_DAYS` config (default 30) to control how many days of
  compressed logs are kept.

## [1.0.0] - 2026-08-31

### Added

- Initial release: `AnalysisFilter`, `AnalysisService`, `Analysis` config and
  `AnalysisSend` command with queue-based asynchronous delivery to an Analysis
  Server. Superseded by 2.0.0.

[2.0.0]: https://github.com/mrnaeem4/ci4-request-analysis/releases/tag/v2.0.0
[1.0.0]: https://github.com/mrnaeem4/ci4-request-analysis/releases/tag/v1.0.0
