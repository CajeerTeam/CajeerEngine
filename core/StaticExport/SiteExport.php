<?php

declare(strict_types=1);
namespace CajeerEngine\StaticExport;

use CajeerEngine\Public\{PublicPageRenderer,PublicPath};
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Media\MediaRepository;

final readonly class SiteExport
{
    public function __construct(private string $root) {}
    private function removeTree(string $dir): void
    {
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
    }
    public function run(): array
    {
        $config=new ConfigRepository($this->root);
        $storage=new StorageManager($this->root,$config);
        if($storage->publicUploadsUrl()!=='/uploads')throw new \RuntimeException('Static export requires STORAGE_PUBLIC_UPLOADS_URL=/uploads');
        $media=new MediaRepository($this->root,$storage);
        $templates=$this->root.'/templates';
        $renderer=new PublicPageRenderer($this->root,new \CajeerEngine\Content\ContentTypeRepository($this->root),new \CajeerEngine\Content\ContentEntryRepository($this->root),new \CajeerEngine\Template\CajeerTemplateEngine(new \CajeerEngine\Template\TemplateSandbox($templates),$templates),$media);
        $target=F::path($this->root,'storage/static-export/site');
        F::dir(dirname($target),0750);
        $lock=fopen(dirname($target).'/.export.lock','c+b');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Cannot lock static export');
        $stage=$target.'.stage-'.bin2hex(random_bytes(8));$old=$stage.'.old';
        try {
            F::dir($stage,0755);$manifest=[];
            foreach($renderer->routeIndex()->all() as $r){
                if($r['kind']!=='page')continue;
                $path=PublicPath::normalize($r['path']);$page=$renderer->renderByPath($path);if($page===null)continue;
                $relative=($path==='/'?'':rawurldecode(ltrim($path,'/')).'/').'index.html';
                F::write(F::path($stage,$relative),$page['html'],0644);$manifest[]=['path'=>$path,'file'=>$relative,'id'=>$r['id']];
            }
            if(!is_file($stage.'/index.html'))throw new \RuntimeException('Publish a homepage before exporting');
            foreach($media->publicItems() as $row){
                $relative='uploads/'.$row['path'];F::copy($media->sourcePath($row),F::path($stage,$relative),0644);
                foreach($row['variants']??[] as $v)F::copy(F::path($this->root,'public/uploads/'.$v['path']),F::path($stage,'uploads/'.$v['path']),0644);
            }
            // Only enabled theme assets, not arbitrary public files or administrative code.
            $registry=new \CajeerEngine\Extension\ExtensionRegistry($this->root,$config);
            foreach(\CajeerEngine\Extension\DependencyGraph::order($registry->all()) as $ext){
                if($ext['type']!=='theme')continue;
                $relative='extensions/'.$ext['slug'];$src=F::path($this->root,'public/'.$relative);
                if(!is_dir($src))continue;
                foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src,\FilesystemIterator::SKIP_DOTS)) as $f){
                    if(!$f->isFile()||$f->isLink())continue;
                    $name=substr($f->getPathname(),strlen($src)+1);F::copy($f->getPathname(),F::path($stage,$relative.'/'.$name),0644);
                }
            }
            F::write($stage.'/sitemap.xml',$renderer->sitemapXml($renderer->baseUrl()),0644);
            if(count($renderer->sitemap())>500)for($i=1;$i<=ceil(count($renderer->sitemap())/500);$i++)F::write($stage.'/sitemap-'.$i.'.xml',$renderer->sitemapXml($renderer->baseUrl(),$i),0644);
            if(is_file($stage.'/sitemap-1.xml'))F::write($stage.'/sitemap.xml',preg_replace('~sitemap\.xml\?page=(\d+)~','sitemap-$1.xml',$renderer->sitemapXml($renderer->baseUrl())),0644);
            F::write($stage.'/robots.txt',$renderer->robotsTxt($renderer->baseUrl()),0644);
            F::json($stage.'/manifest.json',['entries'=>$manifest,'pages_written'=>count($manifest)],0644);
            if(is_dir($target)&&!rename($target,$old))throw new \RuntimeException('Cannot move previous export');
            if(!rename($stage,$target)){if(is_dir($old))rename($old,$target);throw new \RuntimeException('Cannot publish export');}
            if(is_dir($old))$this->removeTree($old);
            return ['ok'=>true,'path'=>$target,'pages_written'=>count($manifest)];
        }finally{if(is_dir($stage))$this->removeTree($stage);flock($lock,LOCK_UN);fclose($lock);}
    }
}
