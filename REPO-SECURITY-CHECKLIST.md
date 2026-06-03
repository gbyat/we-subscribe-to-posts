# Repository Security Checklist

Use this checklist before enabling GitHub-based updates for production/customer sites.

## GitHub repository settings

- Set Actions default workflow permissions to **Read repository contents**.
- Keep "Allow GitHub Actions to create and approve pull requests" **disabled**.
- Restrict Actions to trusted actions:
  - GitHub-owned actions and explicitly allowed actions only.
- Enable branch protection for `main`:
  - Require pull request before merging.
  - Require at least 1 review.
  - Require status checks to pass.
  - Restrict who can push to `main`.
- Add tag protection for `v*`:
  - Only maintainers can create or update release tags.
- Enable Dependabot security updates and secret scanning.
- Store only required secrets; rotate on role changes.

## Workflow hardening

- Use least privilege permissions:
  - PR workflow: `contents: read`.
  - Release workflow: `contents: write` only in publish job.
- Avoid `pull_request_target` for untrusted code.
- Keep `persist-credentials: false` on checkout steps.
- Prefer pinned action SHAs for high-security repositories.

## Release integrity

- Only publish signed, reviewed tags (`vX.Y.Z`).
- Ensure `CHANGELOG.md`, plugin header version, and `package.json` version match tag.
- Verify release asset name is fixed (`we-subscribe-to-posts.zip`).
- Keep GitHub auto-updates disabled by default; enable explicitly per site after validation.

## Operational practice

- Test every release update path on a staging WordPress site first.
- Keep at least one previous release ZIP for rollback.
- Document emergency rollback procedure for customer projects.
