<?php

/**
 * newconsultation.blade.php used to push every newly selected symptom onto
 * the wizard's Alpine state with `severity: 3` already set — indistinguishable
 * from a patient who deliberately picked "3 — Moderate" on the severity
 * picker. Severity now starts unset, and advancing past the symptoms step
 * (or submitting) without picking one is blocked client-side by
 * firstSymptomMissingSeverity() — the same role firstFutureSymptom() already
 * plays for the onset date/time check.
 *
 * This can only assert against the page's source, not runtime Alpine state
 * (Pest has no JS engine) — see AdminInFlightMetricTest.php for the existing
 * precedent for this style of test in this suite. Live behavior for this
 * fix (severity starts unselected, cannot advance without choosing one) was
 * verified interactively via Playwright MCP in the same session, not
 * re-implemented as an automated browser test — see the audit's session
 * notes; no @playwright/test suite exists in this repo and none was added.
 */
it('never initializes a selected symptom with a pre-chosen severity', function () {
    $source = file_get_contents(resource_path('views/patient/newconsultation.blade.php'));

    expect($source)
        ->not->toContain('severity: 3')
        ->toContain('severity: null')
        ->toContain('firstSymptomMissingSeverity');
});

it('blocks advancing to the review step and blocks submission when a symptom has no severity', function () {
    $source = file_get_contents(resource_path('views/patient/newconsultation.blade.php'));

    // canAdvanceToStep(4) — the review-step gate.
    expect($source)->toContain("const unratedSymptom = this.firstSymptomMissingSeverity();");

    // submitForm() — the final defense-in-depth check before the request is sent.
    expect(substr_count($source, 'firstSymptomMissingSeverity()'))->toBeGreaterThanOrEqual(3);
});
