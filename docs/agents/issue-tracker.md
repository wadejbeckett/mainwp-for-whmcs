# Issue tracker

This repository has two places for issues.

- **Public reports** from hosts are GitHub issues at https://github.com/wadejbeckett/mainwp-for-whmcs/issues. Read them with `gh issue list` and `gh issue view <number>`. Do not comment on, label or close one without the maintainer's word.
- **The maintainer's own specs and tickets** are local Markdown files in a tracker folder outside this repository. They are project records, not part of the published repository. The maintainer gives the folder's location for the session; when none is given, ask rather than guess, and never create the folder inside this repository.

## Conventions for the local tracker

Paths below are relative to the tracker folder.

- One feature per directory: `<feature-slug>/`
- A feature's spec is `<feature-slug>/spec.md`
- Implementation issues are one file per ticket at `<feature-slug>/issues/<NN>-<slug>.md`, numbered from `01`, never a single combined tickets file
- Triage state is recorded as a `Status:` line near the top of each issue file
- Comments and conversation history append to the bottom of the file under a `## Comments` heading

## When a skill says "publish to the issue tracker"

Create a new file under `<feature-slug>/` in the tracker folder (creating the directory if needed). Publish to GitHub only when the maintainer asks for it.

## When a skill says "fetch the relevant ticket"

Read the file at the referenced path, or the GitHub issue by its number. The user will normally pass the path or the issue number directly.

## Wayfinding operations

Used by `/wayfinder`. The **map** is a file with one **child** file per ticket.

- **Map**: `<effort>/map.md` (the Notes / Decisions-so-far / Fog body).
- **Child ticket**: `<effort>/issues/NN-<slug>.md`, numbered from `01`, with the question in the body. A `Type:` line records the ticket type (`research`/`prototype`/`grilling`/`task`); a `Status:` line records `claimed`/`resolved`.
- **Blocking**: a `Blocked by: NN, NN` line near the top. A ticket is unblocked when every file it lists is `resolved`.
- **Frontier**: scan `<effort>/issues/` for files that are open, unblocked, and unclaimed; first by number wins.
- **Claim**: set `Status: claimed` and save before any work.
- **Resolve**: append the answer under an `## Answer` heading, set `Status: resolved`, then append a context pointer (gist + link) to the map's Decisions-so-far in `map.md`.
