# Customer-Facing Docs Maintenance

`docs/LUWIPRESS-FEATURES.md` is the **customer reference document** — the user shares it with clients to explain what LuwiPress does. Keep it current. (All long-form `.md` docs live under `docs/`; only `README.md` and `CLAUDE.md` stay in the repo root.)

- Whenever a `feat:` commit ships a user-visible capability (new endpoint, new module, new integration, new safety net, etc.), update `docs/LUWIPRESS-FEATURES.md` to include it in the relevant module section.
- Write in **customer-friendly language** — no internal class names, no regression stories, no implementation details. Focus on what the feature does for the store owner.
- Version line at bottom (`*Document version X.Y.Z*`) should match the latest shipped version in `luwipress/luwipress.php`.
- Bug fixes and refactors generally do NOT need doc updates unless they change observable behavior.
- If unsure whether to document something, err on the side of including it — easier to trim than to recall later what a feature was.
