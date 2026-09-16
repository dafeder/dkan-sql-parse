<?php

namespace SqlParserTest\Command;

use SqlParserTest\QueryTranslator;
use SqlParserTest\SqlStatementParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ParseCommand extends Command
{
    protected function configure()
    {
        $this->setName('parse')
            ->setDescription('Parse sql statement.')
            ->setHelp('Parse that SQL.')
            ->addArgument('sql', InputArgument::REQUIRED, 'SQL Statement')
            ->addOption('resource', 'r', InputOption::VALUE_OPTIONAL, "A resource ID");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sql = $input->getArgument('sql');
        $resource = $input->getOption('resource');
        if ($this->detectLegacy($sql)) {
            $output->write('Legacy SQL endpoint format detected.', true);
            return Command::SUCCESS;
        }

        $parser = new SqlStatementParser();
        try {
            $statement = $parser->parseSelect($sql);
            $query = QueryTranslator::translateStatement($statement, $resource);
        } catch (\Exception $e) {
            throw $e;
        }

        $io = new SymfonyStyle($input, $output);
        $io->newLine();
        $io->write($query->pretty());
        $io->newLine();
        $io->success("Valid DatastoreQuery object returned.");

        return Command::SUCCESS;
    }

    private function detectLegacy(string $sql)
    {
        return (substr($sql, 0, 1) == '[');
    }
}
