# Auth Server TODOs

## Account deletion — live & tested (deployed 2026-11-02)

- [x] End-to-end tested by Andy (request → email → cancel) 2026-11-02
- [ ] Deploy site links: clickforcharity `deploy.sh`, directsponsor `deploy.sh --auto`
      (roflfaucet already live)
- [ ] Cross-site data removal — pull model agreed: `api/deleted-users.php?since=TS`
      endpoint + per-site cron. Design in `account-deletion/README.md` §Follow-up.
- [ ] Optional: block sign-in while a deletion is pending (check
      `.deletion-requests/` at login)

See `~/work/projects/servers/SERVERS_OVERVIEW.md` for server state.
