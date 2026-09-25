<?php

declare(strict_types=1);

use App\OpenApi\DimensionsRuleTransformer;
use App\OpenApi\MimesRuleTransformer;
use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use Illuminate\Validation\Rule;

// Descriptions the app's rule transformers add to a field of the API documentation.

function describe_rule(RuleTransformer $transformer, string|object $rule, string $description = ''): ?string
{
    $normalized = NormalizedRule::fromValue($rule);

    if (! $transformer->shouldHandle($normalized)) {
        return null;
    }

    $context = new RuleTransformerContext('file', collect(), new OpenApi('3.1.0'), Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

    return $transformer->toSchema((new StringType)->setDescription($description), $normalized, $context)->description;
}

it('lists the allowed extensions of a "mimes" rule', function (): void {
    expect(describe_rule(new MimesRuleTransformer, 'mimes:jpg,png'))
        ->toBe('Allowed types: jpg, png (detected from the file content, not its name).');
});

it('describes the dimensions of a rule object and of the equivalent string', function (string|object $rule): void {
    expect(describe_rule(new DimensionsRuleTransformer, $rule))
        ->toBe('Dimensions: at least 500×400 px, at most 6000×5000 px.');
})->with([
    'object' => fn (): object => Rule::dimensions()->minWidth(500)->minHeight(400)->maxWidth(6000)->maxHeight(5000),
    'string' => 'dimensions:min_width=500,min_height=400,max_width=6000,max_height=5000',
]);

it('describes exact dimensions, a single side and a ratio', function (): void {
    expect(describe_rule(new DimensionsRuleTransformer, 'dimensions:width=800,height=600'))
        ->toBe('Dimensions: exactly 800×600 px.')
        ->and(describe_rule(new DimensionsRuleTransformer, 'dimensions:min_width=500,ratio=3/2'))
        ->toBe('Dimensions: at least 500 px wide, aspect ratio 3/2.')
        ->and(describe_rule(new DimensionsRuleTransformer, Rule::dimensions()->minRatio(1)->maxRatio(2)))
        ->toBe('Dimensions: aspect ratio at least 1, aspect ratio at most 2.');
});

it('appends to the description the field already has', function (): void {
    expect(describe_rule(new MimesRuleTransformer, 'mimes:bmp', 'The picture.'))
        ->toBe('The picture. Allowed types: bmp (detected from the file content, not its name).')
        ->and(describe_rule(new DimensionsRuleTransformer, 'dimensions:max_height=10', 'The picture.'))
        ->toBe('The picture. Dimensions: at most 10 px high.');
});

it('leaves the description alone for a dimensions rule without constraints', function (): void {
    expect(describe_rule(new DimensionsRuleTransformer, Rule::dimensions(), 'The picture.'))->toBe('The picture.');
});

it('handles only its own rule', function (): void {
    expect(describe_rule(new MimesRuleTransformer, 'dimensions:min_width=1'))->toBeNull()
        ->and(describe_rule(new DimensionsRuleTransformer, 'mimes:jpg'))->toBeNull()
        ->and(describe_rule(new DimensionsRuleTransformer, 'image'))->toBeNull();
});
