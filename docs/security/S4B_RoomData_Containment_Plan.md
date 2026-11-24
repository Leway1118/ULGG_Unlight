# S4-B-A — Room-data reintroduction guard (Draft, not deployed)

**Scope:** Isolated PR-only security controls for Leway1118/ULGG_Unlight, based on master 7a23f64e. No merge, deployment, live DB/Watcher/cron changes, Git deletion, history rewrite or force-push.

## Observed facts (2026-11-24)

- S4-A successfully denied ulgg.online /steam/data HTTP access with Nginx; retain that independent HTTP block.
- Latest GitHub inventory: 17 public branches. 16 tips still have the main room JSON, all 17 tips have the historical backup JSON; their Git history remains publicly retrievable.
- The historic 47 sampled room objects have Boolean pass fields, not demonstrated plaintext passwords. The exposed records did contain player/deck/room attributes.
- Existing gitignore does not untrack committed files.
- Legacy Python producer/importer read and write on-disk steam/data; delete or relocation requires separate operational approval and regression.
- The active VPS-to-GitHub server-sync code runs rsync, git add -A and pushes the separate server-sync branch. Old rsync and staged file checks did not exclude this path.

## Draft PR changes

1. PR guard refuses new or changed JSON or JSONL anywhere under steam/data, including nested backups and case variants. It also rejects changed JSON elsewhere when it contains the nested playerA/playerB/deckA/deckB room structure. Errors do not print player, deck or room values. Existing unchanged historical files are not altered. Authorized future deletion-only commits remain possible.
2. server-sync rsync exclude now contains root-anchored /steam/data/ to prevent exporting runtime files (including old Python sources) from the VPS. Unrelated pages/assets continue syncing.
3. server-sync staging safety adds a git diff --cached path rejection for steam/data even if earlier sync filters are bypassed. It does not call the live job and is not deployed.
4. Synthetic tests cover positive and negative paths, nested JSON, manual safe JSON, temporary Git changes, real shell staged-path rejection, and real Linux rsync --dry-run. CI automatically discovers these tests via the existing PR-only guard workflow.
5. No workflow receives new secrets or permissions.

## Explicit limitations

- Draft PR CI passing does NOT remove existing public GitHub data in any branch, history, fork, clone, archive or cache.
- A future merge to master would NOT directly update the running VPS sync script: systemd invokes /usr/local/sbin/ulgg-server-sync and the separate server-sync checkout. Any operational upgrade requires separate approval and an actual verification of the executable and exclude file in use.
- Static JSON room-shape detection is a defense in depth heuristic, not complete prevention for raw data under other file formats.
- Existing git history cleanup, branch deletion and force push are not included; related coordination, audit and data-protection review require separate authorization.
- HTTP 200 history proves availability, not evidence of a malicious download.

## Gate checklist

B1: Public branch metadata and exact historical file paths documented, no raw data transferred into this PR.
B2: TDD RED recorded against unmodified baseline; synthetic unit tests GREEN.
B3: Actual isolated rsync excludes synthetic steam/data paths while retaining pages/assets.
B4: Full local PR Quality Gate PASS; actual required GitHub Ubuntu PR CI PASS.
B5: Only a new independent Draft PR and its feature branch pushed; no merge.
B6: Separate future decision on actual server-sync rollout, audited current snapshots and history remediation.

Rollback for this work package: close Draft PR and abandon the local feature branch. Do not undo S4-A Nginx containment as it would reopen sensitive data.

Technical rationale: the HTTPS Nginx deny, repository PR gate, outbound rsync filter and git-staged safeguard protect separate surfaces. Implementing all three code guardrails limits new exposure but does not silently rewrite the already-public record.
