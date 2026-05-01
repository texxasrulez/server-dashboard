# Restore Verification

Restore verification is conservative by default. It does not alter live data.

## Current method

Manual verification runs through:

- `api/backup_verify.php`
- `lib/BackupVerifier.php`

Verification status is stored in:

- `state/backup_restore_verification.json`

The method currently records `integrity-verified`, not a destructive restore test.

## Checks performed

- backup directory exists
- latest artifact exists
- latest artifact size is non-zero
- latest artifact age is reasonable
- lightweight integrity check when feasible:
  - `ZipArchive` open for `.zip`
  - `PharData` open for `.tar`
  - `gzopen()` read for `.gz` / `.tgz`

## UI

The existing Backups page includes:

- last verification result
- per-artifact check summary
- `Run Verification` action
