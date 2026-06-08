<?php

namespace Zuqongtech\LaravelAnvil\Support;

use Zuqongtech\LaravelAnvil\Contracts\Generator;

/**
 * Orchestrates all registered generators across a set of model metadata objects.
 *
 * Two-pass execution:
 *
 *  Pass 1 — generate()
 *    Iterates every ModelMetadata × every Generator.
 *    Each generator that supports() the current options receives the metadata
 *    and returns a result array. Results are collected per model.
 *
 *  Pass 2 — finalize()
 *    Called once after all models are processed. Generators that implement
 *    a finalize() method (e.g. OpenApiRootGenerator which needs to see ALL
 *    models before writing the root spec) are invoked here.
 *    Generators without finalize() are silently skipped.
 */
final class GenerationOrchestrator
{
    /** @var list<Generator> */
    private array $generators = [];

    public function addGenerator(Generator $generator): void
    {
        $this->generators[] = $generator;
    }

    /** @return list<Generator> */
    public function getGenerators(): array
    {
        return $this->generators;
    }

    // -----------------------------------------------------------------------
    // Pass 1 — per-model generation
    // -----------------------------------------------------------------------

    /**
     * Run all applicable generators against a set of model metadata objects.
     *
     * @param  list<ModelMetadata>  $metadata
     * @return list<array{model: string, artifacts: list<array<string, mixed>>}>
     */
    public function generate(array $metadata, GenerationOptions $options): array
    {
        $results = [];

        foreach ($metadata as $meta) {
            $modelResults = ['model' => $meta->model, 'artifacts' => []];

            foreach ($this->generators as $generator) {
                if (! $generator->supports($options)) {
                    continue;
                }

                try {
                    $result = $generator->generate($meta, $options);

                    // Generators may return a single result array OR a list of results
                    // (e.g. EventGenerator returns Created + Updated + Deleted)
                    $artifacts = isset($result['type']) ? [$result] : $result;

                    foreach ($artifacts as $artifact) {
                        $artifact['generator'] = $generator->getName();
                        $modelResults['artifacts'][] = $artifact;
                    }
                } catch (\Throwable $e) {
                    $modelResults['artifacts'][] = [
                        'type' => $generator->getName(),
                        'status' => 'failed',
                        'generator' => $generator->getName(),
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $results[] = $modelResults;
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Pass 2 — post-generation finalization
    // -----------------------------------------------------------------------

    /**
     * Call finalize() on any generator that defines it.
     *
     * This is how OpenApiRootGenerator assembles the root spec after all
     * per-model schemas and paths have been written.
     *
     * @return list<array<string, mixed>> Flat list of finalization results
     */
    public function finalize(GenerationOptions $options): array
    {
        $results = [];

        foreach ($this->generators as $generator) {
            if (! $generator->supports($options)) {
                continue;
            }

            if (method_exists($generator, 'finalize')) {
                try {
                    $finalized = $generator->finalize($options);
                    foreach ((array) $finalized as $result) {
                        $result['generator'] = $generator->getName();
                        $results[] = $result;
                    }
                } catch (\Throwable $e) {
                    $results[] = [
                        'type' => $generator->getName(),
                        'status' => 'failed',
                        'generator' => $generator->getName(),
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }
}
