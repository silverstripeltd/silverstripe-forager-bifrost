<?php

namespace SilverStripe\ForagerBifrost\Processors;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forager\Service\Query\SynonymRule as SynonymRuleQuery;
use SilverStripe\Forager\Service\Results\SynonymRule as SynonymRuleResult;

class SynonymRuleProcessor
{

    use Injectable;

    public static function getStringFromQuery(SynonymRuleQuery $synonymRule): string
    {
        if ($synonymRule->getType() === SynonymRuleResult::TYPE_EQUIVALENT) {
            return implode(', ', $synonymRule->getSynonyms());
        }

        return sprintf(
            '%s => %s',
            implode(', ', $synonymRule->getRoot()),
            implode(', ', $synonymRule->getSynonyms())
        );
    }

    public static function applyStringToResult(SynonymRuleResult $synonymRule, string $synonymsString): void
    {
        $type = str_contains($synonymsString, '=>')
            ? SynonymRuleResult::TYPE_DIRECTIONAL
            : SynonymRuleResult::TYPE_EQUIVALENT;

        $synonymRule->setType($type);

        if ($synonymRule->getType() === SynonymRuleResult::TYPE_DIRECTIONAL) {
            $split = explode('=>', $synonymsString);
            // We'd expect there to always be 2 items
            $root = trim($split[0]);
            $synonyms = trim($split[1]);

            $synonymRule->setRoot(array_map('trim', explode(',', $root)));
            $synonymRule->setSynonyms(array_map('trim', explode(',', $synonyms)));
        } else {
            $synonymRule->setSynonyms(array_map('trim', explode(',', $synonymsString)));
        }
    }

}
