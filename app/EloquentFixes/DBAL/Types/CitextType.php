<?php

declare(strict_types=1);

namespace App\EloquentFixes\DBAL\Types;

/**
 * Name of the PostgreSQL `citext` column type, see AppServiceProvider for how it is registered
 */
final class CitextType
{
    public const CITEXT = 'citext';
}
