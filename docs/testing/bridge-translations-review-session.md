# Bridge Translations review session

A 60–90 minute hands-on test of the Bridge Translations pages (Overview, Review, Keys) with 3–4 people, each signed in with a different level of access.

## Before the session (host)

1. Reset the dev database: `migrate:fresh`, `db:seed`, `bridge:crew`, then `prosetta:sync`.
2. Confirm `composer dev` is running.
3. Create one crew account per role below. Hand out sign-ins privately.
4. As the manager, open the Overview and confirm Spanish, Arabic and Chinese have drafts waiting. If not, press **Run cycle now**.

## Roles

| Role | Permissions | Should see | Should not see or do |
| --- | --- | --- | --- |
| Manager | Manage translations | Every language; Sync, Run cycle now, Export | Nothing off limits |
| Spanish reviewer | Review: Spanish | English and Spanish; Approve, Reject, Approve all | Other languages, even by editing `locale=` in the URL; the operation buttons |
| Arabic translator | Translate: Arabic | English and Arabic; can edit drafts | Approve or Reject; `a` in focus mode does nothing |
| No access | Bridge access only | The Bridge, no Translations tile | Any Translations page by URL |

## Script

### 1. Permissions (everyone)
- [ ] Your Overview shows English plus only your languages.
- [ ] Review and Keys show only your languages; changing `locale=` in the URL doesn't reveal others.
- [ ] Buttons match your role (see the table).

### 2. Review loop (Spanish reviewer, then manager)
- [ ] Approve three rows, reject one with a note, edit and approve one.
- [ ] Approve all: once with warnings ticked, once unticked. The toast count matches what happened.
- [ ] Focus mode, keyboard only, through ten items: none skipped; "Keep as is" works on a stale item.
- [ ] Manager runs Export; the Spanish lang file holds the approved values.

### 3. Conflicts and safety
- [ ] Two people open the same row; both approve. The second is refused with "changed since you loaded it".
- [ ] (Host) With the app set to a non-local environment, pages are read-only with a note.

### 4. Keys page
- [ ] Matrix: pick a module, click a colored cell; it opens that row in the File view.
- [ ] Search a phrase with spaces while typing quickly; nothing is lost.
- [ ] Page through a large group.

### 5. Languages and layout
- [ ] Switch your Bridge language to Arabic: pages read right to left, nothing overlaps or clips (tables, focus view).
- [ ] Narrow the window below 640px or use a phone: tables become labelled cards.
- [ ] Light and dark theme: coverage bars and matrix colors are readable.

## Known rough edges (no need to report)

- Focus mode's counter covers only the current page.
- Shortcuts don't fire while a focus-mode button has focus.
- Keys matrix headers show locale codes (`es`), not names.
- The File view has no tree and no status badges.

## Reporting findings

| # | Tester / role | Page and view | What you did | What happened | What you expected |
| --- | --- | --- | --- | --- | --- |
| 1 | | | | | |

Report correctness problems (sections 1–3) first; layout and wording go to the visual pass.
