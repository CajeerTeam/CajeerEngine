<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

use CajeerEngine\Cms\RedirectRepository;

final readonly class PublicRouteResolver
{
    public function __construct(private RouteIndex $index, private RedirectRepository $redirects) {}

    /** System routes are handled by the HTTP kernel, never by CMS. Live pages win over redirect rules. */
    public function resolve(string $rawPath): array
    {
        try { $path=PublicPath::normalize($rawPath); }
        catch(\InvalidArgumentException){return ['status'=>404,'kind'=>'invalid'];}
        if(PublicPath::reserved($path))return ['status'=>404,'kind'=>'reserved'];
        $row=$this->index->lookup($path);
        if(($row['kind']??null)==='page'){
            if($rawPath!==$path)return ['status'=>308,'kind'=>'normalize','target'=>$path];
            return ['status'=>200,'kind'=>'page','path'=>$path,'route'=>$row];
        }
        $redirect=$this->redirects->match($path);
        if($redirect!==null){
            $status=(int)$redirect['status_code'];
            return $status===410?['status'=>410,'kind'=>'gone']:['status'=>$status,'kind'=>'redirect','target'=>$redirect['target_url']];
        }
        if(($row['kind']??null)==='alias')return ['status'=>301,'kind'=>'alias','target'=>$row['target']];
        return ['status'=>404,'kind'=>'missing','path'=>$path];
    }
}
