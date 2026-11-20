<?php

namespace Innertia\Billing;

/**
 * A meter of the catalog.
 *   aggregation: 'sum' (consumption: messages, minutes) | 'max' (gauge: active users, storage)
 *   valuedByCost: charged at real cost (records carry `cost`) plus the plan markup (ai.cost)
 */
final class MeterDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $unit = 'unidad',
        public readonly string $aggregation = 'sum',
        public readonly bool $valuedByCost = false,
        public readonly string $description = '',
    ) {}

    public function toArray(): array
    {
        return [
            'key'            => $this->key,
            'label'          => $this->label,
            'unit'           => $this->unit,
            'aggregation'    => $this->aggregation,
            'valued_by_cost' => $this->valuedByCost,
            'description'    => $this->description,
        ];
    }
}
