# Change Log

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Added
- Bounded concurrent multirequest support via the new `Correios::batch()` API. Independent price/date requests run in parallel through `curl_multi` with configurable connect, per-request, and global (batch wall-clock) timeouts, returning keyed per-request results with partial successes and structured failures (`['key', 'success', 'code', 'data', 'error']`).
- `Batch::price()` / `Batch::date()` typed helpers, plus a generic `Batch::add(PreparedRequest)` for callers that build their own payloads.
- `Price::prepare()` / `Date::prepare()` build a request without sending it, isolating validation failures (invalid/duplicate CEP, missing weight) per request instead of aborting the batch.
- `AbstractRequest::prepareHandle()` exposes a configured cURL handle for concurrent execution. Existing synchronous `get()` / `sendRequest()` behavior is unchanged.

## [1.0.4] - 2024-10-24

### Fixed
- Updates the CepHandler class to validate both CEPs at the same time and avoid duplication of origin and destination CEPs';


## [1.0.3] - 2024-08-23

### Fixed
- Fix the products dimention names;

### Added
- Set default lotId property value in Correios class;
- Create getLotId method in Correios class;
- Create getRequestNumber method in Correios class;
- Create getDr method in Authentication class;
- Create getContract method in Authentication class;

## [1.0.1] - 2023-11-22

### Fixed

- Fix the tracking filter param usage


## [1.0.0] - 2023-09-08

### Added

- Initial release 