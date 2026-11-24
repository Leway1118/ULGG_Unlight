# Raid Collector G2-R4 — actual systemd staging (synthetic, ephemeral, no production)

## Target and safety boundary

G2-R3 verified Unix socket/UID permissions in a Linux container, and verified systemd units statically. G2-R4 verifies **actual systemd PID1 service execution** in a disposable GitHub-hosted Ubuntu VM, not the Contabo production VPS.

- The test uses an isolated stacked Draft PR based on PR #14; it does not merge PR #13 or #14 and does not deploy any production service.
- The runner must be GITHUB_ACTIONS=true, RUNNER_ENVIRONMENT=github-hosted, explicitly set G2R4_EPHEMERAL_STAGING=synthetic-only, run as root on the disposable runner, and have /proc/1/comm == systemd. Otherwise STOP.
- The script dynamically copies ONLY the existing synthetic Gatekeeper/Worker and safety modules. No real Steam ID, TW Session, token, Watcher data, game endpoint or DB credentials are used.
- It renders hardened unit properties from the G2-R3 example units, pins a unique ephemeral /run socket root and /var/lib StateDirectory, creates two synthetic non-root UIDs, and uses real systemctl start/restart/stop.
- It has no ability to connect to game servers or write production data.

## Acceptance criteria

1. Verify real PID1 systemd and actual Gatekeeper MainPID, PrivateNetwork=yes.
2. Compare host and manager /proc/PID/ns/net network namespace inode; they must be different.
3. An ExecStartPre probe runs in the real manager unit sandbox. Both AF_INET and AF_INET6 socket creation must fail under RestrictAddressFamilies=AF_UNIX. No outbound connection is attempted.
4. Validate manager socket mode 0660, UID manager, GID worker; StateDirectory manager-owned and inaccessible to worker.
5. Run the Worker as a real Type=oneshot systemd service against a synthetic fixture. First run yields VERIFIED_COMPLETE; a second start fails with ExecMainStatus=2.
6. Restart Gatekeeper with systemctl restart and prove the Journal still blocks attempts. SIGTERM handled gracefully to remove only the stale Unix socket; never Journal.
7. Incorrect approval/missing synthetic independent-cleanup flag cannot release. Only manager UID with exact operator phrase may archive the synthetic Journal. This is a simulated approval, not third-party verified clearance.
8. Change synthetic Worker scenario to cleanup-failed, start the same systemd service, verify BLOCKED_PENDING_CLEANUP and no replay.
9. Verify audit file owner-only permissions, capture SHA256 and event count without sharing raw account identifiers.
10. ALWAYS stop/remove only generated ephemeral systemd units, delete synthetic users/groups and test state, daemon-reload. Report rollback=PASS only after removal.
11. Upload a synthetic-only JSON report to GitHub Actions; no secrets emitted.

## Files

- tests/linux_systemd_g2r4_staging.py — actual systemd orchestration; refuses any non-GitHub-hosted or non-systemd-PID1 environment.
- tests/test_g2r4_systemd_staging_contract.py — local/offline safety contracts.
- run_gatekeeper_fixture.py — graceful SIGTERM/SIGINT shutdown; Journal and audit remain.
- .github/workflows/raid-collector-g2r4-systemd-staging.yml — read-only token, own-branch PR trigger, synthetic only.
- The full offline regression must stay green.

## Unresolved live gates

This test does not authorize live Unlight operations, Collector deployment, MariaDB writes or PR merging. Even a green G2-R4 run does not prove TW Steam ID/session binding, real Raid join/delete cleanup, AP side-effect safety, or third-party evidence validation. All remain separate gates with separate approvals.
