<?php

declare(strict_types=1);

namespace App\EloquentFixes\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use RuntimeException;

final class MlpGenerationType extends TextType
{
    /**
     * CAUTION! This name refers to a database type and must be modified along
     * with a migration to recreate it with the new name.
     */
    public const MLP_GENERATION = 'mlp_generation';

    /** @inheritDoc */
    public function getName()
    {
        return self::MLP_GENERATION;
    }

    /**
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform)
    {
        if ($platform instanceof PostgreSQL94Platform === false) {
            throw new RuntimeException('You are meant to run this site with a PostgreSQL database');
        }

        return self::MLP_GENERATION;
    }
}
