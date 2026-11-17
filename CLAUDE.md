# MainWP for WHMCS

A WHMCS provisioning module (`modules/servers/mainwp`, five functions only, nothing required at file level; never declare `mainwp_ClientArea` there, it is the add-on's `mainwp_clientarea`) with an add-on module beside it (folder `modules/addons/mainwp`, namespace `MainWpForWhmcs`, tables `mod_mainwp_*`) that puts each site of a care product add-on on a MainWP dashboard through MainWP's REST API and keeps it in step with billing. "MainWP for WHMCS" is the module, which only staff read. The service a client reads is the host's own care product add-on, by its name in WHMCS, read live and never stored by the module; the code holds no service name of its own beyond the neutral fallback. Nothing a client reads names MainWP. The one place the module writes a fixed care name on a client's site is the care account's login prefix "wpcare" (`Secrets::CARE_LOGIN_PREFIX`). `tests/NamesTest.php` holds these rules. `README.md` is for hosts, `docs/reference.md` is the full behaviour, and `GLOSSARY.md` is the vocabulary.

- Two layers: `lib/Engine/` is plain PHP with no WHMCS call (dashboard, site, storage and clock interfaces); everything else is the WHMCS layer. Keep WHMCS out of the engine.
- Test-first: `composer test` (PHPUnit, WHMCS stubs in `tests/Support/FakeWhmcs.php`) and `composer stan` (PHPStan at the PHP 8.2 floor, `tools/whmcs-stubs.php`).
- Never log or render a password, key or token, and no file path in log output.
- Nothing about one host's own set-up (its add-on numbers, clients, servers or names) goes into the code, the tests or the documents.
- Nothing is deployed to a live WHMCS or dashboard, and nothing is tagged or released, without the maintainer's word.

## Agent skills

### Issue tracker

Public reports are GitHub issues on this repository; the maintainer's own tickets are local Markdown outside the repository. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context: `GLOSSARY.md` at the repo root. See `docs/agents/domain.md`.
