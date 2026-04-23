# Mail Sender Strategy

Use one canonical sender identity everywhere:

- Header `From:` comes from `mail.mail_from` or `MAIL_FROM`.
- Envelope sender comes from `mail.mail_envelope_from` or `MAIL_ENVELOPE_FROM`.
- If no envelope sender is configured, the app falls back to the bare email extracted from `mail.mail_from`.

Why this matters:

- Exim uses the envelope sender for bounces.
- If PHP or a shell script sends mail without an explicit envelope sender, the MTA can fall back to the runtime account such as `www-data@genesworld.net` or `root@host`.
- Those synthetic local senders create frozen bounce junk and noisy mail logs.

Recommended local-server setup:

- Set `mail.mail_transport` to `sendmail` when local Exim submission is working and `/usr/sbin/sendmail` is available.
- Set both `mail.mail_from` and `mail.mail_envelope_from` to a real hosted mailbox such as `gene@genesworld.net`.
- Set `mail.mail_replyto` only if replies should go somewhere else.

Operational notes:

- PHP `mail()` sends now pass `-f <envelope>`.
- Direct `sendmail` scripts now pass `-f <envelope>` and include `From:` plus `Sender:`.
- SMTP sends use the same envelope sender for `MAIL FROM`.
- `Return-Path` is not forced as a manual header because the real bounce path is defined by the envelope sender.
