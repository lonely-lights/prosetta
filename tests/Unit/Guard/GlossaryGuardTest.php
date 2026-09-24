<?php

use LonelyLights\Prosetta\Guard\GlossaryGuard;

$glossary = [['source' => 'cohort', 'target' => 'دفعة', 'banned' => ['فوج', 'مجموعة']]];

it('passes a translation that uses the required term', function () use ($glossary) {
    expect((new GlossaryGuard)->check('Your cohort convenes.', 'تلتقي دفعتك. دفعة', $glossary))->toBe([]);
});

it('warns when the required term is missing, and matches whole words case-insensitively', function () use ($glossary) {
    $issues = (new GlossaryGuard)->check('Two Cohorts arrive.', 'يصل الأعضاء.', $glossary);

    expect(array_map(fn ($issue) => $issue->code, $issues))->toBe(['glossary_missing'])
        ->and((new GlossaryGuard)->check('A cohortless start.', 'بداية', $glossary))->toBe([]);
});

it('errors on a banned term, which blocks', function () use ($glossary) {
    $issues = (new GlossaryGuard)->check('Your cohort convenes.', 'يلتقي فوجك مع دفعة.', $glossary);

    expect(array_map(fn ($issue) => $issue->code, $issues))->toBe(['glossary_banned'])
        ->and($issues[0]->isBlocking())->toBeTrue()
        ->and($issues[0]->message)->toContain('دفعة')->toContain('فوج');
});

it('errors on a banned term even when the required term is missing', function () use ($glossary) {
    $issues = (new GlossaryGuard)->check('Your cohort convenes.', 'يلتقي فوجك.', $glossary);

    expect(array_map(fn ($issue) => $issue->code, $issues))->toBe(['glossary_banned', 'glossary_missing'])
        ->and($issues[0]->isBlocking())->toBeTrue();
});

it('checks nothing when the glossary is empty', function () {
    expect((new GlossaryGuard)->check('Your cohort convenes.', 'x', []))->toBe([]);
});

it('accepts any form in the entry\'s accept list in place of the target', function () {
    $glossary = [['source' => 'cohort', 'target' => 'دفعة', 'accept' => ['دفعت', 'دفعات'], 'banned' => ['فوج']]];

    expect((new GlossaryGuard)->check('Your cohort convenes.', 'تلتقي دفعتك.', $glossary))->toBe([])
        ->and((new GlossaryGuard)->check('Two cohorts arrive.', 'تصل دفعات.', $glossary))->toBe([])
        ->and((new GlossaryGuard)->check('Your Cohort convenes.', 'COHORT', [['source' => 'cohort', 'target' => 'grupo', 'accept' => ['Cohort']]]))->toBe([]);
});

it('still names the target when neither it nor an accepted form appears', function () {
    $glossary = [['source' => 'cohort', 'target' => 'دفعة', 'accept' => ['دفعت', 'دفعات']]];
    $issues = (new GlossaryGuard)->check('Your cohort convenes.', 'يصل الأعضاء.', $glossary);

    expect(array_map(fn ($issue) => $issue->code, $issues))->toBe(['glossary_missing'])
        ->and($issues[0]->message)->toContain('دفعة')->not->toContain('دفعات');
});

it('ignores blank accepted forms', function () {
    $glossary = [['source' => 'cohort', 'target' => 'دفعة', 'accept' => ['', '  ']]];

    expect(array_map(fn ($issue) => $issue->code, (new GlossaryGuard)->check('Your cohort.', 'x', $glossary)))->toBe(['glossary_missing']);
});
