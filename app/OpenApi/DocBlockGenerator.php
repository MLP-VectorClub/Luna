<?php

namespace App\OpenApi;

use L5Swagger\Generator;
use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\ReflectionAnalyser;
use OpenApi\Generator as OpenApiGenerator;

/**
 * l5-swagger 10+ scans attributes only, while the API is still documented in docblocks (which swagger-php parses through
 * doctrine/annotations). An analyser object cannot live in config/l5-swagger.php, `config:cache` (`artisan optimize`) could not store it.
 */
class DocBlockGenerator extends Generator
{
    protected function setAnalyser(OpenApiGenerator $generator): void
    {
        $generator->setAnalyser(new ReflectionAnalyser([new AttributeAnnotationFactory(), new DocBlockAnnotationFactory()]));
    }
}
