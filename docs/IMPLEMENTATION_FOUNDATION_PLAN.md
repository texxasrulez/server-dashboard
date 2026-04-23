# Foundation Work Plan

This scratch note tracks the current production-readiness pass.

## Priority Order

1. Preflight / environment doctor
2. Testing and CI baseline
3. Token/security management UX
4. Reporting/export groundwork
5. README/docs cleanup

## Current Implementation Notes

- Reuse existing config bootstrap and `\App\Config` instead of adding a second settings layer.
- Keep cron token compatibility with current file/env/config lookup chain.
- Add shared helpers for:
  - cron token reads/writes
  - reveal-session authorization
  - audit logging
  - file permission diagnostics
- Extend `diag.php` into an actionable PASS/WARN/FAIL admin doctor.
- Add a minimal Composer/PHPUnit baseline plus shell-based PHP linting for CI.
- Add monthly uptime reporting on top of existing JSONL history.
- Update docs only after code paths and commands are real.
