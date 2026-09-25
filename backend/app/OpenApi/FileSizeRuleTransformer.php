<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\RulesMapper;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;

/**
 * Scramble documents "max" on a file as "Maximum file size: 5120 kilobytes." Laravel's unit is
 * KiB, which a client may well read as 1000 bytes, so the limit is also given in bytes. A
 * transformer replaces Scramble's own handling of the rule, so any other field is handed back.
 */
final readonly class FileSizeRuleTransformer implements RuleTransformer
{
    public function __construct(private RulesMapper $rules) {}

    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is('max');
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        $parameters = $rule->getParameters();

        if (! $previous instanceof StringType || $previous->format !== 'binary' || ! is_numeric($parameters[0] ?? null)) {
            /** @var Type */
            return $this->rules->max($previous, $parameters);
        }

        $kibibytes = (int) $parameters[0];
        $bytes = number_format($kibibytes * 1024);

        return $previous->setDescription(trim("{$previous->description} Maximum file size: {$kibibytes} KiB ({$bytes} bytes)."));
    }
}
