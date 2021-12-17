<?php

namespace App\Utils;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Routing\ResponseFactory as BaseResponseFactory;
use Illuminate\Support\Str;
use function is_array;
use function is_object;

/**
 * Extends the base response factory with a method that converts JSON keys to camelCase
 */
class CamelCaseJsonResponseFactory extends BaseResponseFactory
{
    public function camelJson($data = array(), $status = 200, array $headers = array(), $options = 0)
    {
        $json = $this->encodeJson($data);
        return $this->json($json, $status, $headers, $options);
    }

    /**
     * Encode a value to camelCase JSON
     * @param $value
     * @return mixed
     */
    protected function encodeJson($value)
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Arrayable) {
            return $this->encodeArrayable($value);
        }

        if (is_array($value)) {
            return $this->encodeArray($value);
        }

        if (is_object($value)) {
            return $this->encodeArray((array) $value);
        }

        return $value;
    }

    /**
     * Encode an arrayable
     * @param  Arrayable  $arrayable
     * @return mixed
     */
    protected function encodeArrayable(Arrayable $arrayable)
    {
        $array = $arrayable->toArray();
        return $this->encodeJson($array);
    }

    /**
     * Encode an array
     * @param  array  $array
     * @return array
     */
    protected function encodeArray(array $array): array
    {
        $newArray = [];
        foreach ($array as $key => $val) {
            $newArray[Str::camel($key)] = $this->encodeJson($val);
        }
        return $newArray;
    }
}
