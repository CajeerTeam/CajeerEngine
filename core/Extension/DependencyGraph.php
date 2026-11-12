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
    public static function matches(string $version,string $constraint): bool
    {
        if ($constraint==='*') return true;
        foreach (preg_split('/\s+/',trim($constraint)) as $part) {
            if (!preg_match('/^(\^|~|>=|<=|>|<|=)?(\d+\.\d+\.\d+)$/D',$part,$m)) throw new \RuntimeException('Unsupported dependency constraint: '.$constraint);
            $op=$m[1]?:'='; $v=$m[2]; [$a,$b,$c]=array_map('intval',explode('.',$v));
            if ($op==='^'||$op==='~') { $upper=$op==='~'?"$a.".($b+1).'.0':($a>0?($a+1).'.0.0':($b>0?'0.'.($b+1).'.0':'0.0.'.($c+1))); if (version_compare($version,$v,'<')||version_compare($version,$upper,'>=')) return false; }
            elseif (!version_compare($version,$v,$op)) return false;
        } return true;
    }
}
