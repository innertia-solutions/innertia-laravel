<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Innertia\Workflow\Enums\WorkflowStatus;
use Innertia\Workflow\Events\WorkflowTransitionBlocked;
use Innertia\Workflow\Exceptions\WorkflowRestrictionException;
use Innertia\Workflow\Models\WorkflowDefinition;
use Innertia\Workflow\Models\WorkflowInstance;
use Innertia\Workflow\WorkflowEngine;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeEngineUser(array $roles = []): Authenticatable
{
    return new class($roles) implements Authenticatable {
        public function __construct(public array $roleList) {}
        public function getAuthIdentifier() { return 'user-uuid-123'; }
        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthPassword() { return ''; }
        public function getRememberToken() { return null; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return ''; }
        public function getAuthPasswordName() { return 'password'; }
        public function getRoles(): \Illuminate\Support\Collection
        {
            return collect($this->roleList)->map(fn($slug) => (object)['slug' => $slug]);
        }
        public function getAttribute($key) {
            if ($key === 'roles') return $this->getRoles();
            return null;
        }
    };
}

function makeEngineWorkflowable(): Model
{
    return new class extends Model {
        public $exists = true;
        protected $keyType = 'string';
        public function getKey() { return 'entity-uuid-456'; }
        public function __get($key) {
            if ($key === 'tenant_id') return 'tenant-uuid';
            return parent::__get($key);
        }
    };
}

function makeEngineDefinition(array $steps, array $transitions, ?string $entityType = null): WorkflowDefinition
{
    $def = new WorkflowDefinition();
    $def->id          = 'def-uuid-789';
    $def->tenant_id   = null;
    $def->name        = 'test_flow';
    $def->label       = 'Test Flow';
    $def->entity_type = $entityType ?? get_class(makeEngineWorkflowable());
    $def->config      = ['steps' => $steps, 'transitions' => $transitions];
    $def->exists      = true;
    return $def;
}

function simpleEngineFlow(): WorkflowDefinition
{
    return makeEngineDefinition(
        steps: [
            ['key' => 'planning',  'label' => 'Planificación', 'type' => 'start'],
            ['key' => 'execution', 'label' => 'Ejecución',    'type' => 'in_progress'],
            ['key' => 'closure',   'label' => 'Cierre',       'type' => 'finished'],
        ],
        transitions: [
            ['from' => 'planning',  'to' => 'execution', 'restrictions' => []],
            ['from' => 'execution', 'to' => 'closure',   'restrictions' => []],
        ],
    );
}

/**
 * Build a mock WorkflowInstance that does not require a DB connection.
 * The mock overrides isActive() and the definition relation.
 */
function makeEngineInstance(string $currentStep, WorkflowDefinition $definition, bool $active = true): WorkflowInstance
{
    $instance = Mockery::mock(WorkflowInstance::class)->makePartial();
    $instance->current_step = $currentStep;
    $instance->id           = 'instance-uuid';
    $instance->shouldReceive('isActive')->andReturn($active);
    $instance->shouldReceive('getAttribute')->with('definition')->andReturn($definition);
    return $instance;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('lanza excepción si entity_type no coincide', function () {
    $engine     = new WorkflowEngine();
    $definition = makeEngineDefinition([], [], 'App\\Models\\Project');
    $other      = new class extends Model {
        public $exists = true;
        public function getKey() { return 'x'; }
    };

    expect(fn() => $engine->start($other, $definition))
        ->toThrow(\InvalidArgumentException::class);
});

it('lanza excepción si la definición no tiene step de tipo start', function () {
    $engine       = new WorkflowEngine();
    $workflowable = makeEngineWorkflowable();
    $definition   = makeEngineDefinition(
        steps: [['key' => 'execution', 'label' => 'Ejecución', 'type' => 'in_progress']],
        transitions: [],
        entityType: get_class($workflowable),
    );

    expect(fn() => $engine->start($workflowable, $definition))
        ->toThrow(\InvalidArgumentException::class, "no tiene step de tipo 'start'");
});

it('bloquea transición y dispara WorkflowTransitionBlocked cuando falla restricción de rol', function () {
    Event::fake([WorkflowTransitionBlocked::class]);

    $engine = new WorkflowEngine();

    $definition = makeEngineDefinition(
        steps: [
            ['key' => 'planning',  'label' => 'Planificación', 'type' => 'start'],
            ['key' => 'execution', 'label' => 'Ejecución',    'type' => 'in_progress'],
        ],
        transitions: [
            [
                'from' => 'planning',
                'to'   => 'execution',
                'restrictions' => [
                    ['type' => 'role', 'roles' => ['admin'], 'message' => 'Solo admin puede avanzar'],
                ],
            ],
        ],
    );

    $userWithoutRole = makeEngineUser(['viewer']);
    $instance        = makeEngineInstance('planning', $definition);

    expect(fn() => $engine->transition($instance, 'execution', $userWithoutRole))
        ->toThrow(WorkflowRestrictionException::class, 'Solo admin puede avanzar');

    Event::assertDispatched(WorkflowTransitionBlocked::class, function ($e) {
        return $e->blockedBy === 'role';
    });
});

it('canTransition retorna false si la transición no existe', function () {
    $engine     = new WorkflowEngine();
    $definition = simpleEngineFlow();
    $user       = makeEngineUser();

    $instance = makeEngineInstance('planning', $definition);

    expect($engine->canTransition($instance, 'closure', $user))->toBeFalse();
    expect($engine->canTransition($instance, 'execution', $user))->toBeTrue();
});

it('availableTransitions retorna solo los steps válidos para el usuario', function () {
    $engine     = new WorkflowEngine();
    $definition = simpleEngineFlow();
    $user       = makeEngineUser();

    $instance = makeEngineInstance('planning', $definition);

    $available = $engine->availableTransitions($instance, $user);

    expect($available)->toHaveCount(1);
    expect($available[0]['key'])->toBe('execution');
});

it('resolveEvaluator lanza excepción para tipo desconocido', function () {
    $engine = new WorkflowEngine();

    $definition = makeEngineDefinition(
        steps: [
            ['key' => 'planning',  'label' => 'Planificación', 'type' => 'start'],
            ['key' => 'execution', 'label' => 'Ejecución',    'type' => 'in_progress'],
        ],
        transitions: [
            ['from' => 'planning', 'to' => 'execution', 'restrictions' => [
                ['type' => 'unknown_type'],
            ]],
        ],
    );

    $instance = makeEngineInstance('planning', $definition);

    expect(fn() => $engine->transition($instance, 'execution', makeEngineUser()))
        ->toThrow(\InvalidArgumentException::class, 'unknown_type');
});
