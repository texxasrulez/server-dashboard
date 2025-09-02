# README Policy — Source of Truth

This repository is a **drop‑in** project. The ZIP you received is the **entire context**. Ignore any earlier versions or conversations.

- Keep the README in sync with any new folders/files you add.
- Do **not** assume deployment at web root. All paths must be **relative** within the project.
- No inline CSS/JS in pages. Put styles in `/assets/css/*` and scripts in `/assets/js/*`.
- Add a small `docs/INSTRUCTIONS_*.md` whenever you add or modify behavior, explaining how to tune it and where to find it.
- Prefer **debug flags** in APIs and **console logging** in JS with a `loggedOnce` guard.
