<?php

namespace App\Utils;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Contracts\Support\Arrayable;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Spatie\Enum\Enum;
use Spatie\Enum\EnumDefinition;
use TypeError;
use function get_class;
use function in_array;
use function is_object;

abstract class EnumWrapper extends Enum implements CastsInboundAttributes
{
    private static array $value_cache = [];
    private static array $instance_map = [];

    public function __construct($value = null)
    {
        if ($value !== null) {
            parent::__construct($value);
        }
    }

    /**
     * Copy of the {@see Enum::resolveDefinition} method that only resolves values
     *
     * @return mixed[]
     */
    public static function getValues(): array
    {
        $class_name = static::class;

        if (isset(static::$value_cache[$class_name])) {
            return static::$value_cache[$class_name];
        }

        $definition = self::resolveDefinitionImproved();

        $values = array_map(fn (EnumDefinition $def) => $def->value, array_values($definition));

        return static::$value_cache[$class_name] ??= $values;
    }

    /**
     * @return EnumDefinition[]
     * @throws ReflectionException
     */
    protected static function resolveDefinitionImproved(): array
    {
        $reflection_class = new ReflectionClass(static::class);

        $method = $reflection_class->getMethod('resolveDefinition');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    public function get($model, string $key, $value, array $attributes)
    {
        $class_name = static::class;

        static::generateInstanceMap();

        $method_name = static::$instance_map[$class_name][$value] ?? null;

        if ($method_name === null) {
            throw new RuntimeException(
                "Enum $class_name does not have a method matching value ".var_export($value, true)
            );
        }

        return new static($method_name);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        $enum_class = static::class;

        $value_class = is_object($value) ? get_class($value) : null;
        if ($value_class !== $enum_class) {
            static::generateInstanceMap();

            if (!isset(static::$instance_map[$enum_class][$value])) {
                throw new TypeError(sprintf(
                    "Trying to assign $key on %s to $value which cannot be mapped to enum $enum_class",
                    get_class($model)
                ));
            }

            return $value;
        }

        /** @var static $value */
        return $value->value;
    }

    private static function generateInstanceMap(): void
    {
        $class_name = static::class;

        if (!isset(static::$instance_map[$class_name])) {
            static::$instance_map[$class_name] = [];
            foreach (self::resolveDefinitionImproved() as $method_name => $def) {
                static::$instance_map[$class_name][$def->value] = $method_name;
            }
        }
    }
}
