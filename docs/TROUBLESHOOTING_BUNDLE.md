# Troubleshooting Bundle

Troubleshooting bundles are generated manually from the Backups page.

## Endpoint

- `api/support_bundle.php`

## Builder

- `lib/SupportBundle.php`

## Output

Bundles are written to:

- `state/support_bundles/support_bundle_YYYYmmdd_HHMMSS.zip`

Download is authenticated and explicit. Bundles are not generated automatically.

## Contents

- `manifest.json`
- `summary.txt`
- `config_summary.json`
- `services.json`
- `incidents.json`
- `audit.json`
- `backup_verification.json`
- `logs/alerts_events.tail.jsonl`

## Redaction policy

Bundle contents use the central redaction helper. Secrets are redacted by default.
