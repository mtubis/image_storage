<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use Illuminate\Validation\Rules\Dimensions;

/**
 * Documents the "dimensions" rule, as a string or a Rule::dimensions() object, which Scramble
 * otherwise leaves out.
 */
final readonly class DimensionsRuleTransformer implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is('dimensions') || $rule->is(Dimensions::class);
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        $constraints = $this->constraints($rule);

        $limits = array_filter([
            'at least' => $this->size($constraints, 'min_width', 'min_height'),
            'at most' => $this->size($constraints, 'max_width', 'max_height'),
            'exactly' => $this->size($constraints, 'width', 'height'),
            'aspect ratio' => $constraints['ratio'] ?? null,
            'aspect ratio at least' => $constraints['min_ratio'] ?? null,
            'aspect ratio at most' => $constraints['max_ratio'] ?? null,
        ]);

        if ($limits === []) {
            return $previous;
        }

        $parts = array_map(fn (string $limit, string $value): string => "{$limit} {$value}", array_keys($limits), $limits);

        return $previous->setDescription(trim("{$previous->description} Dimensions: ".implode(', ', $parts).'.'));
    }

    /**
     * The rule's parameters as name => value, e.g. ['min_width' => '500'].
     *
     * @return array<string, string>
     */
    private function constraints(NormalizedRule $rule): array
    {
        $object = $rule->getRule();

        // The object's constraints are protected; its string form is the equivalent string rule.
        $parameters = $object instanceof Dimensions
            ? explode(',', explode(':', (string) $object, 2)[1] ?? '')
            : array_filter($rule->getParameters(), is_string(...));

        $constraints = [];

        foreach ($parameters as $parameter) {
            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            $constraints[$name] = $value;
        }

        return $constraints;
    }

    /**
     * @param  array<string, string>  $constraints
     */
    private function size(array $constraints, string $width, string $height): ?string
    {
        return match (true) {
            isset($constraints[$width], $constraints[$height]) => "{$constraints[$width]}×{$constraints[$height]} px",
            isset($constraints[$width]) => "{$constraints[$width]} px wide",
            isset($constraints[$height]) => "{$constraints[$height]} px high",
            default => null,
        };
    }
}
