<?php

/**
 * A Phase 1 comment ("Moderate") inside the wizard's x-data="{ ... }"
 * attribute contained a literal double-quote character. The HTML parser has
 * no concept of JS comments — it closed the double-quote-delimited x-data
 * attribute right there, silently dropping everything after it (including
 * canAdvanceToStep, submitForm, and the rest of the wizard's logic). Alpine
 * then threw "X is not defined" for every property on the component
 * (confirmed live via Playwright: 109 console errors, and the object's
 * serialized length was ~1.7KB instead of the real ~15KB). Locks this in at
 * the source level, since Pest cannot execute the page's JS to catch it the
 * way a browser would.
 */
it('never contains a literal double-quote inside the x-data HTML attribute', function () {
    $source = file_get_contents(resource_path('views/patient/newconsultation.blade.php'));

    $start = strpos($source, 'x-data="{') + strlen('x-data="');
    $end = strpos($source, '}" class="py-12">', $start) + 1;

    expect($start)->toBeGreaterThan(0, 'Could not locate the x-data attribute — has the wizard markup changed?');
    expect($end)->toBeGreaterThan($start, 'Could not locate the closing of the x-data attribute.');

    $attributeBody = substr($source, $start, $end - $start);

    // Blade comments are compiled away and @json(...) always emits properly
    // escaped output, so neither can reintroduce this bug — strip/normalize
    // them before scanning for a literal, HTML-attribute-breaking `"`.
    $attributeBody = preg_replace('/\{\{--.*?--\}\}/s', '', $attributeBody);
    $attributeBody = preg_replace('/@json\([^)]*\)/', 'true', $attributeBody);

    expect($attributeBody)->not->toContain('"');
});
