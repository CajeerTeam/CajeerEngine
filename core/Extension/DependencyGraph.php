<?php
declare(strict_types=1);
namespace CajeerEngine\Extension;

/** Resolves enabled dependencies before a single provider is executed. */
final class DependencyGraph
{
    public static function order(array $items): array
    {
        $nodes=[]; foreach ($items as $item) { if (empty($item['enabled'])||empty($item['installed'])) continue; $name=$item['name']; if (isset($nodes[$name])||empty($item['valid'])||!empty($item['missing'])) throw new \RuntimeException('Invalid enabled extension: '.$name); $nodes[$name]=$item; }
        if (count(array_filter($nodes,static fn($n)=>$n['type']==='theme'))>1) throw new \RuntimeException('Only one active theme is supported');
        ksort($nodes); $marks=[]; $out=[];
        $visit=function(string $name) use (&$visit,&$marks,&$out,$nodes): void {
            if (($marks[$name]??0)===1) throw new \RuntimeException('Extension dependency cycle: '.$name);
            if (($marks[$name]??0)===2) return;
            $marks[$name]=1; $item=$nodes[$name];
            foreach ($item['conflicts']??[] as $dep=>$constraint) if (isset($nodes[$dep])&&self::matches($nodes[$dep]['version'],$constraint)) throw new \RuntimeException($name.' conflicts with '.$dep);
            foreach ($item['requires']??[] as $dep=>$constraint) { if (!isset($nodes[$dep])||!self::matches($nodes[$dep]['version'],$constraint)) throw new \RuntimeException($name.' requires enabled '.$dep.' '.$constraint); $visit($dep); }
            $marks[$name]=2; $out[]=$item;
        };
        foreach (array_keys($nodes) as $name) $visit($name); return $out;
    }
    public static function matches(string $version, string $constraint): bool
    {
        return VersionConstraint::matches($version, $constraint);
    }
}
