# Member reports

Members who read your app in their own language are the first to spot a translation that reads wrong. Prosetta lets them report it, and puts the report in front of a reviewer without changing anything live.

## Recording a report

Call `Reports::report()` from your own route, for example when a member selects some text and presses "Report translation":

```php
use LonelyLights\Prosetta\Review\Reports;

app(Reports::class)->report(
    locale: 'es',
    selectedText: $request->input('text'),
    by: $request->user(),
    suggestion: $request->input('suggestion'),  // optional: what it should say
    notes: $request->input('notes'),            // optional: why
    url: $request->input('url'),                // optional: where they saw it
);
```

If you already know which string it is, pass `keyRef:` too. Otherwise Prosetta finds the one current translation in that language whose approved text contains the selected words.

A report needs a signed-in member, at least 3 selected characters, and a language Prosetta translates. Each member can have one open report per string. `report()` throws a `ProsettaException` with a message you can show the member when any of these fails.

**Throttle the route** that calls it, like any form members can submit.

## Where a report goes

A report never overwrites anyone's work, and nothing live changes until a reviewer approves something.

- **If a draft or edit is already waiting** for that string, the report is added to it as a `reported` warning, with the member's note and suggestion.
- **Otherwise**, where the string is current and can be changed here, the report becomes a waiting edit of its own, so it shows in the review queue as `pending`.

`QueueItem::$reports` counts the open reports on each item, and reported strings are never swept up by "approve all matching".

Some reports can't go to the queue: the words matched no single string, or the string is stale, or it can't be changed in this environment. `Reports::unqueued($viewer)` lists those for staff to handle, and `Reports::dismiss($reportId, $user)` closes one.

## Closing reports

- A person **approving** the string closes its reports as `accepted`.
- A person **rejecting** it closes them as `dismissed`.
- The background cycle's own approvals leave them open, so a person always sees them.

The reporter counts as the author of their suggestion, so with [`allow_self_approval`](workflow.md#who-may-do-what) off, a reporter can't approve their own report.
