<?php

declare(strict_types=1);

namespace App\EloquentFixes\DBAL\Types;

/**
 * Name of the PostgreSQL `mlp_generation` enum type, see AppServiceProvider for how it is registered
 */
final class MlpGenerationType
{
    /**
     * CAUTION! This name refers to a database type and must be modified along
     * with a migration to recreate it with the new name.
     */
    public const MLP_GENERATION = 'mlp_generation';
}
