# Working Agreement

- ChatGPT directs; Codex executes the approved scope.
- Work on one explicit objective at a time.
- Do not perform a full-project audit unless the task requires it.
- Reuse the existing architecture before adding parallel systems.
- Use RTK for potentially voluminous human-readable output.
- Never expose, copy, log, or commit secrets, cookies, tokens, sessions, or private keys.
- Do not deploy, merge, commit, or push unless explicitly authorized.
- Never run `docker compose down -v`.
- Run the local stack with `docker compose --env-file .\.env.local -f .\compose.local.yml`.
- The PHP backend is contained in the app image; backend changes may require rebuilding that image.
- The unpacked Browser Companion requires **Reload** in Opera after JavaScript changes.
- Preserve the database and Docker volumes unless deletion is explicitly authorized.
- Run targeted tests before broader suites.
