<?php

use App\Models\Consultation;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\SymptomAnalytics;
use App\Support\DateRange;

function dashPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function dashNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function dashPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function dashRequest(User $patient, array $overrides = []): Consultation
{
    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    $this->service = new DashboardAnalyticsService(new SymptomAnalytics);
    $this->range = DateRange::fromInput('this_year', null, null);
});

// --- Nurse: shared queue vs. personal workload ------------------------------

it('counts the shared unclaimed queue independent of who is asking', function () {
    $patient = dashPatient();
    $nurse = dashNurse();
    dashRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => null]);
    dashRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => $nurse->user_id]);

    $result = $this->service->forNurse($nurse, $this->range);

    expect($result['operational']['unclaimed_pending'])->toBe(1);
});

it('does not change the unclaimed queue count when the date range changes', function () {
    $patient = dashPatient();
    $nurse = dashNurse();
    dashRequest($patient, [
        'request_status' => 'pending',
        'assigned_nurse_id' => null,
        'submitted_at' => now()->subYears(5), // well outside any normal range
    ]);

    $short = $this->service->forNurse($nurse, DateRange::fromInput('today', null, null));
    $long = $this->service->forNurse($nurse, DateRange::fromInput('this_year', null, null));

    expect($short['operational']['unclaimed_pending'])->toBe(1)
        ->and($long['operational']['unclaimed_pending'])->toBe(1);
});

it('scopes my_open_cases to the assigned nurse only, excluding another nurse\'s cases', function () {
    $patient = dashPatient();
    $nurseA = dashNurse();
    $nurseB = dashNurse();
    dashRequest($patient, ['request_status' => 'reviewed', 'assigned_nurse_id' => $nurseA->user_id]);
    dashRequest($patient, ['request_status' => 'active', 'assigned_nurse_id' => $nurseB->user_id]);

    $result = $this->service->forNurse($nurseA, $this->range);

    expect($result['operational']['my_open_cases']['total'])->toBe(1);
});

it('counts my_completed only within the selected period and only for this nurse', function () {
    $patient = dashPatient();
    $nurse = dashNurse();
    dashRequest($patient, [
        'request_status' => 'completed',
        'assigned_nurse_id' => $nurse->user_id,
        'submitted_at' => now()->subMonths(2),
    ]);
    dashRequest($patient, [
        'request_status' => 'completed',
        'assigned_nurse_id' => $nurse->user_id,
        'submitted_at' => now()->subYears(3), // outside "this_year"
    ]);

    $result = $this->service->forNurse($nurse, $this->range);

    expect($result['period']['my_completed'])->toBe(1);
});

// --- Physician: personal scope, not system-wide -----------------------------

it('scopes active_now to the assigned physician only', function () {
    $patient = dashPatient();
    $physicianA = dashPhysician();
    $physicianB = dashPhysician();
    dashRequest($patient, ['request_status' => 'active', 'assigned_physician_id' => $physicianA->user_id]);
    dashRequest($patient, ['request_status' => 'active', 'assigned_physician_id' => $physicianB->user_id]);

    $result = $this->service->forPhysician($physicianA, $this->range);

    expect($result['operational']['active_now'])->toBe(1);
});

it('excludes another physician\'s consultations from period metrics', function () {
    $patient = dashPatient();
    $physicianA = dashPhysician();
    $physicianB = dashPhysician();
    dashRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => $physicianA->user_id]);
    dashRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => $physicianB->user_id]);

    $result = $this->service->forPhysician($physicianA, $this->range);

    expect($result['period']['completed'])->toBe(1);
});

it('computes the physician completion rate as completed over concluded, excluding in-flight', function () {
    $patient = dashPatient();
    $physician = dashPhysician();
    dashRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => $physician->user_id]);
    dashRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => $physician->user_id]);
    dashRequest($patient, ['request_status' => 'rejected', 'assigned_physician_id' => $physician->user_id]);
    // In-flight — must be excluded from both numerator and denominator.
    dashRequest($patient, ['request_status' => 'active', 'assigned_physician_id' => $physician->user_id]);

    $result = $this->service->forPhysician($physician, $this->range);
    $rate = $result['period']['completion_rate'];

    expect($rate['completed'])->toBe(2)
        ->and($rate['concluded'])->toBe(3)
        ->and($rate['rate'])->toBe(66.7);
});

it('returns a null completion rate rather than dividing by zero when nothing has concluded', function () {
    $patient = dashPatient();
    $physician = dashPhysician();
    dashRequest($patient, ['request_status' => 'pending', 'assigned_physician_id' => $physician->user_id]);

    $result = $this->service->forPhysician($physician, $this->range);

    expect($result['period']['completion_rate']['rate'])->toBeNull()
        ->and($result['period']['completion_rate']['concluded'])->toBe(0);
});

// --- Admin: system-wide, not scoped to any one staff member -----------------

it('aggregates admin totals across every nurse and physician, not just one', function () {
    $patient = dashPatient();
    dashRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => dashPhysician()->user_id]);
    dashRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => dashPhysician()->user_id]);

    $result = $this->service->forAdmin($this->range);

    expect($result['period']['total_requests'])->toBe(2)
        ->and($result['period']['completed'])->toBe(2);
});

it('computes the admin completion rate with the same completed-over-concluded rule', function () {
    $patient = dashPatient();
    dashRequest($patient, ['request_status' => 'completed']);
    dashRequest($patient, ['request_status' => 'cancelled']);
    dashRequest($patient, ['request_status' => 'scheduled']); // in-flight, excluded

    $rate = $this->service->forAdmin($this->range)['period']['completion_rate'];

    expect($rate['completed'])->toBe(1)
        ->and($rate['concluded'])->toBe(2)
        ->and($rate['rate'])->toBe(50.0);
});

it('reports operational total_pending and total_active without being affected by the date range', function () {
    $patient = dashPatient();
    dashRequest($patient, ['request_status' => 'pending', 'submitted_at' => now()->subYears(5)]);
    dashRequest($patient, ['request_status' => 'active', 'submitted_at' => now()->subYears(5)]);

    $result = $this->service->forAdmin(DateRange::fromInput('today', null, null));

    expect($result['operational']['total_pending'])->toBe(1)
        ->and($result['operational']['total_active'])->toBe(1);
});

// --- Charts ------------------------------------------------------------------

it('never includes the dead assigned status as a category in the status distribution chart', function () {
    $result = $this->service->forAdmin($this->range);

    expect($result['charts']['status_distribution']['labels'])->not->toContain('assigned');
});

it('zero-fills a day with no submissions in the volume-over-time chart rather than omitting it', function () {
    // Anchored to the middle of the week: DateRange's 'this_week' starts
    // at Carbon's startOfWeek() (Monday), so running this test for real on
    // a Monday made the range exactly 1 day wide and failed
    // toBeGreaterThan(1) below — a date-boundary flake in the test, not a
    // defect in DateRange or the service. Travelling to this week's own
    // Wednesday keeps the range multi-day regardless of which real
    // calendar day the suite happens to run on.
    test()->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));

    $patient = dashPatient();
    $range = DateRange::fromInput('this_week', null, null);
    dashRequest($patient, ['submitted_at' => $range->start]); // only the first day has data

    $series = $this->service->forAdmin($range)['charts']['volume_over_time'];

    $totalLabels = count($series['labels']);
    $totalPoints = count($series['datasets'][0]['data']);

    expect($totalLabels)->toBeGreaterThan(1)
        ->and($totalPoints)->toBe($totalLabels)
        ->and($series['datasets'][0]['data'])->toContain(0);
});

it('splits initial vs follow-up using the initial scope, which treats type correctly', function () {
    $patient = dashPatient();
    dashRequest($patient, ['type' => 'initial']);
    dashRequest($patient, ['type' => 'follow_up', 'parent_consultation_id' => null]);

    $series = $this->service->forAdmin($this->range)['charts']['initial_vs_follow_up'];
    $byLabel = array_combine($series['labels'], $series['datasets'][0]['data']);

    expect($byLabel['Initial'])->toBe(1)
        ->and($byLabel['Follow-up'])->toBe(1);
});

// --- Admin symptoms wiring: follow-up exclusion is load-bearing here --------

it('excludes follow-up requests from admin symptom analytics to avoid double-counting inherited symptoms', function () {
    $patient = dashPatient();
    dashRequest($patient, [
        'type' => 'initial',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
    ]);
    // A follow-up that copied the parent's symptoms verbatim.
    dashRequest($patient, [
        'type' => 'follow_up',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
    ]);

    $symptoms = $this->service->forAdmin($this->range)['symptoms'];

    expect($symptoms['standardized'])->toContain(['name' => 'Headache', 'count' => 1]);
});
