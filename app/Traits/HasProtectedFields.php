<?php


namespace App\Traits;

/**
 * This trait will add handling for the `protected_fields` static
 * property which will be appended to the `hidden` property.
 *
 * The special method can be used to create an array representation
 * that contains those hidden fields.
 */
trait HasProtectedFields
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        foreach ($this->protected_fields as $field) {
            $this->hidden[] = $field;
        }
    }

    public function toArrayWithProtected(?callable $filter = null): array
    {
        $data = $this->toArray();
        foreach ($this->protected_fields as $field) {
            $data[$field] = $this->{$field};
        }
        if ($filter !== null) {
            $filter($data);
        }
        return $data;
    }
}
