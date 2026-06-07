<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Zuqongtech\LaravelAnvil\Contracts\Generator;

final class GenerationOrchestrator
{
    /**
     * @param  array<Generator>  $generators
     */
    public function __construct(
        private array $generators = []
    ) {}

    public function addGenerator(Generator $generator): void
    {
        $this->generators[] = $generator;
    }

    /**
     * Run all applicable generators
     *
     * @param  array<ModelMetadata>  $metadata
     */
    public function generate(array $metadata, GenerationOptions $options): array
    {
        $results = [];

        foreach ($metadata as $meta) {
            $modelResults = ['model' => $meta->model, 'artifacts' => []];

            foreach ($this->generators as $generator) {
                if ($generator->supports($options)) {
                    $result = $generator->generate($meta, $options);
                    $result['type'] = $generator->getName();
                    $modelResults['artifacts'][] = $result;
                }
            }

            $results[] = $modelResults;
        }

        return $results;
    }
}
