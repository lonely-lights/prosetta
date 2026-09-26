# Reviewing in production

Interface text normally gets approved in development, because only there can an approval reach the lang files in git. But your reviewers may be on the live site, not on a developer's machine. Prosetta lets them approve there, and brings their approvals back into your lang files.

Database content doesn't need any of this: it's approved and served in production directly. See [Translating database content](database-content.md).

## How it works

1. Reviewers approve interface text on the live site.
2. Production keeps those approvals, but doesn't write lang files.
3. In development, `prosetta:pull` fetches the approvals, applies them and exports the lang files.
4. You commit the lang files and deploy as usual.

## Setting it up

**On both sides**, set the same secret token:

```dotenv
PROSETTA_PULL_TOKEN=a-long-random-string
```

**In production**, stop lang file writes and allow review:

```dotenv
PROSETTA_EXPORT_ENABLED=false
PROSETTA_REVIEW_EDITABLE=true
```

Production then serves its approvals at `prosetta/approvals` (change it with `PROSETTA_PULL_PATH`). Without the token, that address returns a 404.

**In development**, point at it:

```dotenv
PROSETTA_PULL_URL=https://your-app.com/prosetta/approvals
```

The URL must use https everywhere except your `local` environment.

## Pulling

```bash
php artisan prosetta:pull --export
```

This:

1. syncs your lang files, so it compares against the latest English (skip with `--no-sync`);
2. applies each approval whose English still matches yours. A developer's edit waiting for review is kept, with only the approved wording changing under it;
3. credits the production reviewer in the review trail;
4. lists anything it skipped, and why;
5. exports the lang files (with `--export`).

Then commit the lang files.

Each pull asks only for approvals newer than the last one it applied. To pull again from an earlier point, pass `--after=<id>`.
