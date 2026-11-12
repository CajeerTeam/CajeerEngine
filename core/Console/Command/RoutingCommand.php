<?php

declare(strict_types=1);
namespace CajeerEngine\Console\Command;
use CajeerEngine\Public\RouteIndex;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
final class RoutingCommand extends BaseCommand
{
    public function __construct(string $root,private readonly string $operation){parent::__construct($root);$this->setName($operation)->setDescription('Check or rebuild the public route index');}
    protected function configure():void{$this->addOption('json',null,InputOption::VALUE_NONE,'JSON output');}
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        try{$index=new RouteIndex($this->rootPath);$result=$this->operation==='routing:rebuild'?$index->rebuild():$index->check();$output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return $result['ok']?self::SUCCESS:self::FAILURE;}
        catch(\Throwable $e){$output->writeln('[FAIL] '.$e->getMessage());return self::FAILURE;}
    }
}
