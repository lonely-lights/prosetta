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
