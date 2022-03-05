<?php

namespace App\SwaggerProcessors;

use Illuminate\Support\Str;
use OpenApi\Analysis;
use OpenApi\Annotations\Operation;


class OperationIdProcessor
{
    // Generate reasonable looking operation IDs in OpenAPI documentation
    public function __invoke(Analysis $analysis)
    {
        /** @var Operation[] $all_operations */
        $all_operations = $analysis->getAnnotationsOfType(Operation::class);


        foreach ($all_operations as $operation) {
            $operation->operationId = ucfirst(
                Str::camel(
                    trim(
                        preg_replace(
                            '~_{2,}~',
                            '_',
                            preg_replace(
                                '~[^a-z_]~i',
                                '_',
                                implode('_', [$operation->method, $operation->path])
                            )
                        ),
                        '_'
                    )
                )
            );
        }
    }
}
