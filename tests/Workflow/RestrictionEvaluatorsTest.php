<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Models\WorkflowInstance;
use Innertia\Workflow\Restrictions\ApprovalRestriction;
use Innertia\Workflow\Restrictions\ChecklistRestriction;
use Innertia\Workflow\Restrictions\DateRestriction;
use Innertia\Workflow\Restrictions\DocumentRestriction;
use Innertia\Workflow\Restrictions\MinItemsRestriction;
use Innertia\Workflow\Restrictions\RequiredFieldsRestriction;
use Innertia\Workflow\Restrictions\RoleRestriction;
use Innertia\Workflow\Restrictions\CustomRestriction;

// Helper to create a mock WorkflowInstance with context
function makeInstance(array $context): WorkflowInstance
{
    $instance = new WorkflowInstance();
    $instance->context = $context;
    return $instance;
}

// Helper to create a mock Authenticatable
function makeUser(array $data = []): Authenticatable
{
    return new class($data) implements Authenticatable {
        public function __construct(private array $data) {}
        public function getAuthIdentifierName(): string { return 'id'; }
        public function getAuthIdentifier(): mixed { return $this->data['id'] ?? 1; }
        public function getAuthPasswordName(): string { return 'password'; }
        public function getAuthPassword(): string { return ''; }
        public function getRememberToken(): ?string { return null; }
        public function setRememberToken($value): void {}
        public function getRememberTokenName(): string { return ''; }
    };
}

// ── Checklist ──────────────────────────────────────────────────────────────

test('ChecklistRestriction passes when all items completed', function () {
    $restriction = new ChecklistRestriction();
    $instance = makeInstance([
        'checklists' => [
            'planning_items' => [
                ['label' => 'Item 1', 'completed' => true],
                ['label' => 'Item 2', 'completed' => true],
            ],
        ],
    ]);

    expect($restriction->evaluate(['checklist' => 'planning_items'], $instance, makeUser()))->toBeTrue();
});

test('ChecklistRestriction fails when any item not completed', function () {
    $restriction = new ChecklistRestriction();
    $instance = makeInstance([
        'checklists' => [
            'planning_items' => [
                ['label' => 'Item 1', 'completed' => true],
                ['label' => 'Item 2', 'completed' => false],
            ],
        ],
    ]);

    expect($restriction->evaluate(['checklist' => 'planning_items'], $instance, makeUser()))->toBeFalse();
});

test('ChecklistRestriction fails when checklist is empty', function () {
    $restriction = new ChecklistRestriction();
    $instance = makeInstance(['checklists' => []]);

    expect($restriction->evaluate(['checklist' => 'missing'], $instance, makeUser()))->toBeFalse();
});

// ── RequiredFields ─────────────────────────────────────────────────────────

test('RequiredFieldsRestriction passes when all fields present', function () {
    $restriction = new RequiredFieldsRestriction();
    $instance = makeInstance(['name' => 'Test', 'scope' => 'ISO 9001']);

    expect($restriction->evaluate(['fields' => ['name', 'scope']], $instance, makeUser()))->toBeTrue();
});

test('RequiredFieldsRestriction fails when field is missing', function () {
    $restriction = new RequiredFieldsRestriction();
    $instance = makeInstance(['name' => 'Test']);

    expect($restriction->evaluate(['fields' => ['name', 'scope']], $instance, makeUser()))->toBeFalse();
});

test('RequiredFieldsRestriction fails when field is empty string', function () {
    $restriction = new RequiredFieldsRestriction();
    $instance = makeInstance(['name' => '']);

    expect($restriction->evaluate(['fields' => ['name']], $instance, makeUser()))->toBeFalse();
});

// ── Approval ───────────────────────────────────────────────────────────────

test('ApprovalRestriction passes when approval exists', function () {
    $restriction = new ApprovalRestriction();
    $instance = makeInstance(['approvals' => ['quality_manager' => ['user_id' => 'uuid-1', 'approved_at' => now()->toIso8601String()]]]);

    expect($restriction->evaluate(['role' => 'quality_manager'], $instance, makeUser()))->toBeTrue();
});

test('ApprovalRestriction fails when approval missing', function () {
    $restriction = new ApprovalRestriction();
    $instance = makeInstance(['approvals' => []]);

    expect($restriction->evaluate(['role' => 'quality_manager'], $instance, makeUser()))->toBeFalse();
});

// ── Document ───────────────────────────────────────────────────────────────

test('DocumentRestriction passes when document present', function () {
    $restriction = new DocumentRestriction();
    $instance = makeInstance(['documents' => ['audit_report' => 'uuid-file-1']]);

    expect($restriction->evaluate(['document' => 'audit_report'], $instance, makeUser()))->toBeTrue();
});

test('DocumentRestriction fails when document missing', function () {
    $restriction = new DocumentRestriction();
    $instance = makeInstance(['documents' => []]);

    expect($restriction->evaluate(['document' => 'audit_report'], $instance, makeUser()))->toBeFalse();
});

// ── Date ───────────────────────────────────────────────────────────────────

test('DateRestriction passes when current date is after the limit', function () {
    $restriction = new DateRestriction();
    $instance = makeInstance([]);

    expect($restriction->evaluate(['after' => now()->subDay()->toDateString()], $instance, makeUser()))->toBeTrue();
});

test('DateRestriction fails when current date is before the limit', function () {
    $restriction = new DateRestriction();
    $instance = makeInstance([]);

    expect($restriction->evaluate(['after' => now()->addDay()->toDateString()], $instance, makeUser()))->toBeFalse();
});

// ── CustomRestriction — resolveId ──────────────────────────────────────────

test('CustomRestriction returns false when context key missing', function () {
    $restriction = new CustomRestriction();
    $instance = makeInstance([]); // no program_id

    $config = [
        'entity'  => \stdClass::class,
        'id_from' => 'context.program_id',
        'field'   => 'status',
        'value'   => 'active',
    ];

    expect($restriction->evaluate($config, $instance, makeUser()))->toBeFalse();
});
