# AI costs, and source comments in translated files (step 8, item 4)

Status: designed and built by Claude while the owner was away (owner's instruction, 2026-09-26: "decide and build; I'll review"). Not merged.

## In plain words

**Costs.** Prosetta counted tokens but not money. Each usage row now records the model that spent it, and a small price list (per million input and output tokens, per model) turns tokens into a cost. The Overview shows each language's cost this month beside its tokens, and the whole month's total, where prices are known. The price list lives in config by default; a host can supply its own (a database table, say) by binding one interface.

**Comments.** A developer's comments in the English lang files (a file's heading, a note above a group of keys) now appear in the same places in every translated file Prosetta writes, so the files read the same in every language.

## Decisions

| # | Decision | Why |
| --- | --- | --- |
| K1 | `prosetta_usage` gains a nullable `model` column (edited in place: the package isn't published yet; Undaunted's copy likewise, pre-launch) | Price depends on the model; old rows stay unpriced rather than guessed |
| K2 | `Contracts\PriceCatalogue::price(string $model): ?ModelPrice`; the default `ConfigPriceCatalogue` reads `prosetta.ai.prices` (`'model' => ['input' => 3.0, 'output' => 15.0]`, per million tokens) and `prosetta.ai.currency` (default `USD`) | Config for most hosts, a binding for a host that keeps prices in its database |
| K3 | `UsageLedger::cost()` returns an amount and how many tokens it couldn't price (unknown model or no price); the Overview says "about" when some went unpriced | A number that silently leaves things out would mislead |
| K4 | Budgets stay in tokens | Changing what a limit means is a separate decision |
| C1 | Comments are read from the source PHP file with PHP's tokenizer: the header (between `declare` and `return`) and the comment immediately above any key, at any depth | Exact positions, no regex over code |
| C2 | Comments are copied verbatim (they are developer notes, in English) above the same keys in each target file; a key the target doesn't write takes its comment with it | The files stay parallel and diff cleanly |
| C3 | JSON files have no comments; only PHP files are affected | JSON can't carry them |
