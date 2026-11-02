# Auth Server TODOs

## Account deletion — live, pending user test (deployed 2026-11-02)

- [ ] Andy: test full flow with a disposable account (see checklist in
      `account-deletion/README.md`)
- [ ] Cross-site data removal — pull model agreed: `api/deleted-users.php?since=TS`
      endpoint + per-site cron. Design in `account-deletion/README.md` §Follow-up.
- [ ] Optional: block sign-in while a deletion is pending (check
      `.deletion-requests/` at login)

See `~/work/projects/servers/SERVERS_OVERVIEW.md` for server state.
