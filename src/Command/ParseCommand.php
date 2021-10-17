<?php

namespace SqlParserTest\Command;

use PHPSQLParser\PHPSQLParser;
use SqlParserTest\DatastoreQuery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\VarDumper\VarDumper;

class ParseCommand extends Command
{
    const DEFAULT_RESOURCE = 't';
    const CONDITION_PROCESSOR = 'conditionBracketExpression';
    const CONDITION_GROUP_PROCESSOR = 'conditionGroupBracketExpression';
    const EXPRESSION_PROCESSOR = 'expressionBracketExpression';

    protected function configure()
    {
        $this->setName('parse')
            ->setDescription('Parse sql statement.')
            ->setHelp('Parse that SQL.')
            ->addArgument('sql', InputArgument::REQUIRED, 'SQL Statement');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sql = $input->getArgument('sql');
        
        if ($this->detectLegacy($sql)) {
            $output->write('Legacy SQL endpoint format detected.', true);
            return Command::SUCCESS;
        }

        $parser = new PHPSQLParser($sql);
        VarDumper::dump($parser->parsed);
        try {
            $query = $this->translate($parser->parsed);
        } catch (\Exception $e) {
            VarDumper::dump($e);
            throw $e;
        }

        return Command::SUCCESS;
    }

    private function detectLegacy(string $sql) 
    {
        return (substr($sql, 0, 1) == '[');
    }

    protected function translate($parsed)
    {
        $query = [];

        if (isset($parsed['FROM'])) {
            foreach ($parsed['FROM'] as $from) {
                $query['resources'][] = $this->translateTree($from);
            }
        }
        if (isset($parsed['SELECT'])) {
            foreach ($parsed['SELECT'] as $select) {
                $query['properties'][] = $this->translateTree($select);
            }
        }
        if (isset($parsed['WHERE'])) {
            $query['conditions'] = $this->conditionNormalizer($parsed['WHERE']);
        }

        VarDumper::dump($query);
        // return new DatastoreQuery($query);
    }

    private function translateTree($tree)
    {
        $translateFunc = $this->getTranslateFunc($tree);
        return $this->$translateFunc($tree);
    }

    private function getTranslateFunc($tree)
    {
        $translateFunc = lcfirst(implode('', array_map('ucfirst', explode('_', $tree['expr_type']))));
        $translateFunc = lcfirst(implode('', array_map('ucfirst', explode('-', $translateFunc))));
        return $translateFunc;
    }

    private function table($tree)
    {
        $resource = [];
        $resource['id'] = $tree['no_quotes']['parts'][0] ?? null;
        $resource['alias'] = $tree['alias']['name'] ?? 't';
        return array_filter($resource);
    }

    private function colref($tree)
    {
        $property = [];
        $parts = $tree['no_quotes']['parts'];
        $property['property'] = end($parts);
        $property['resource'] = count($parts) > 1 ? $parts[0] : self::DEFAULT_RESOURCE;
        $property['alias'] = $select['alias']['name'] ?? null;
        return array_filter($property);
    }

    private function const($tree)
    {
        return $tree['base_expr'];
    }


    private function operator($tree)
    {
        return strtolower($tree['base_expr']);
    }

    private function getBracketExpressionProcessor($tree)
    {
        foreach ($tree['sub_tree'] as $part) {
            if ($part['expr_type'] == 'operator') {
                $operator = $this->translateTree($part);
            }
        }

        if (in_array($operator, DatastoreQuery::conditionOperators())) {
            return self::CONDITION_PROCESSOR;
        }
        if (in_array($operator, DatastoreQuery::conditionGroupOperators())) {
            return self::CONDITION_GROUP_PROCESSOR;
        }
        if (in_array($operator, DatastoreQuery::expressionOperators())) {
            return self::EXPRESSION_PROCESSOR;
        }
        return false;
    }

    private function inList($tree)
    {
        $list = [];
        foreach ($tree['sub_tree'] as $subTree) {
            $list[] = $this->translateTree($subTree);
        }
        return $list;
    }

    private function bracketExpression($tree)
    {
        $func = $this->getBracketExpressionProcessor($tree);
        return $this->$func($tree);
    }

    private function conditionBracketExpression($tree)
    {
        $property = $this->translateTree($tree['sub_tree'][0]);
        $condition = [
            'property' => $property['property'],
            'resource' => $property['resource'] ?? null,
            'operator' => $this->translateTree($tree['sub_tree'][1]),
            'value' => $this->translateTree($tree['sub_tree'][2]),
        ];
        return array_filter($condition);
    }

    private function expressionBracketExpression($tree)
    {
        $subTree = $tree['sub_tree'];
        $expression = ['expression' => ['operator' => $this->translateTree($subTree[1])]];
        unset($subTree[1]);

        foreach ($subTree as $operand) {
            $expression['expression']['operands'][] = $this->translateTree($operand);
        }
        $expression['alias'] = $tree['alias']['name'] ?? null;
        return array_filter($expression);
    }

    private function conditionGroupBracketExpression($tree)
    {
        $subTree = $tree['sub_tree'];
        $conditionGroup = ['groupOperator' => $this->translateTree($subTree[1])];
        unset($subTree[1]);

        foreach ($subTree as $condition) {
            $conditionGroup['conditions'][] = $this->translateTree($condition);
        }
    }

    private function gatherConditionGroupOperators($tree)
    {
        $subTree = $tree['sub_tree'];
        $operators = [];
        foreach ($subTree as $part) {
            $func = $this->getTranslateFunc($part);
            if ($func == 'operator') {
                $operators[] = $this->operator($part);
            }
        }
        $operators = array_unique($operators);
        if (count($operators) > 1) {
            throw new \Exception("Condition groups must all use the same operator (AND or OR).");
        }
        return array_pop($operators);
    }

    private function removeConditionGroupOperators($tree)
    {
        $subTree = $tree['sub_tree'];
        $operators = [];
        foreach ($subTree as $part) {
            $func = $this->getTranslateFunc($part);
            if ($func != 'operator') {
                $conditions[] = $this->operator($part);
            }
        }
        return $conditions;
    }

    /**
     * The top-level conditions will contain a group operator.
     *
     * @param array $conditions
     * @return array
     */
    private function conditionNormalizer(array $where)
    {
        if (count($where) == 1) {
            VarDumper::dump($this->translateTree($where[0]));
            return [$this->translateTree($where[0])];
        }
        if (count($where) == 3) {
            $bracketExpression = [
                'sub_tree' => $where,
            ];
            VarDumper::dump($bracketExpression);
            $func = $this->getBracketExpressionProcessor($bracketExpression);
            return $this->$func($bracketExpression);
        }
        foreach ($where as $index => $condition) {
            if (is_string($condition)) {
                $groupOperators[] = $condition;
                unset($conditions[$index]);
            }
        }
        if (empty($groupOperators)) {
            if (count($where) == 1) {
                return [$this->translateTree($where[0])];
            }
            elseif (count($where) == 3) {
                return [$this->conditionBracketExpression($where)];
            }
        }
        if (count(array_unique($groupOperators)) > 1) {
            throw new \Exception("You must not mix operators within a condition group.");
        }
        if (array_pop($groupOperators) == 'or') {
            return [
                'groupOperator' => 'or',
                'conditions' => array_values($conditions),
            ];
        }
        return array_values($conditions);
    }

}
