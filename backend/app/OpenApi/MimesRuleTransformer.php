<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;

/**
 * Documents the "mimes" rule (File::types() builds one), which Scramble otherwise leaves out,
 * although the allowed types are what a client most needs to know about an upload.
 */
final readonly class MimesRuleTransformer implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is('mimes');
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        $extensions = implode(', ', array_filter($rule->getParameters(), is_string(...)));

        // "mimes" compares against the type sniffed from the content, so a renamed file does not pass.
        return $previous->setDescription(trim("{$previous->description} Allowed types: {$extensions} (detected from the file content, not its name)."));
    }
}
