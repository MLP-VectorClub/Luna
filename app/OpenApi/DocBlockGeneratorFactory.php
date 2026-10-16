<?php

namespace App\OpenApi;

use L5Swagger\ConfigFactory;
use L5Swagger\Generator;
use L5Swagger\GeneratorFactory;
use L5Swagger\SecurityDefinitions;

/** Same as l5-swagger's factory, but makes a {@see DocBlockGenerator} */
class DocBlockGeneratorFactory extends GeneratorFactory
{
    public function __construct(private readonly ConfigFactory $configs)
    {
        parent::__construct($configs);
    }

    public function make(string $documentation): Generator
    {
        $config = $this->configs->documentationConfig($documentation);

        return new DocBlockGenerator(
            $config['paths'],
            $config['constants'] ?? [],
            $config['generate_yaml_copy'] ?? false,
            new SecurityDefinitions($config['securityDefinitions']['securitySchemes'] ?? [], $config['securityDefinitions']['security'] ?? []),
            $config['scanOptions'] ?? [],
        );
    }
}
