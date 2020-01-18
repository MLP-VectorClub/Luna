<?php

declare(strict_types=1);

namespace App\EloquentFixes\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

final class Citext extends TextType
{
    const CITEXT = 'citext';

    /** @inheritDoc */
    public function getName()
    {
        return self::CITEXT;
    }

    /** @inheritDoc */
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getDoctrineTypeMapping(self::CITEXT);
    }
}
