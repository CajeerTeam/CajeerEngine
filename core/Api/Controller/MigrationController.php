<?php
declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Migration\{MigrationCenter,WxrDiscovery};
use CajeerEngine\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\{JsonResponse,Request};

final readonly class MigrationController
{
    public function __construct(private MigrationCenter $center,private AuditLogger $audit){}
    private function response(array $data,int $status=200):JsonResponse{return new JsonResponse(['data'=>$data],$status,['Cache-Control'=>'no-store']);}
    private function record(Request $r,string $action,string $id):void{$this->audit->record('migration.discovery.'.$action,(string)($r->attributes->get('auth')['user_id']??''),['project_id'=>$id]);}
    public function index(Request $r):JsonResponse{return $this->response($this->center->list());}
    public function show(Request $r,array $p):JsonResponse{return $this->response($this->center->show($p['id']));}
    public function create(Request $r):JsonResponse{$v=$this->center->create($r->attributes->get('json',[]));$this->record($r,'created',$v['id']);return $this->response($v,201);}
    public function update(Request $r,array $p):JsonResponse{$v=$this->center->update($p['id'],$r->attributes->get('json',[]));$this->record($r,'updated',$p['id']);return $this->response($v);}
    public function delete(Request $r,array $p):JsonResponse{$this->center->delete($p['id'],$r->attributes->get('json',[])['revision']);$this->record($r,'deleted',$p['id']);return $this->response(['deleted'=>true]);}
    public function scan(Request $r,array $p):JsonResponse{$v=$this->center->scan($p['id'],$r->attributes->get('json',[])['revision']);$this->record($r,$v['status'],$p['id']);return $this->response($v);}
    public function catalog(Request $r):JsonResponse{return $this->response($this->center->catalog());}
    public function mapping(Request $r,array $p):JsonResponse{$b=$r->attributes->get('json',[]);$v=$this->center->saveMapping($p['id'],$b['revision'],$b['mapping']);$this->record($r,'mapped',$p['id']);return $this->response($v);}
    public function plan(Request $r,array $p):JsonResponse{$v=$this->center->dryRun($p['id'],$r->attributes->get('json',[])['revision']);$this->record($r,'planned',$p['id']);return $this->response($v);}
    public function approve(Request $r,array $p):JsonResponse{$b=$r->attributes->get('json',[]);$v=$this->center->approve($p['id'],$b['revision'],$b['sha256']);$this->record($r,'approved',$p['id']);return $this->response($v);}
    public function upload(Request $r,array $p):JsonResponse{
        $file=$r->files->get('file');$rev=$r->request->get('revision');
        if(!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile||!$file->isValid()||$file->getSize()>WxrDiscovery::MAX_BYTES||!is_string($rev)||!ctype_digit($rev)||strlen($rev)>10)throw new \InvalidArgumentException('Valid WXR file (max 32 MiB) and revision required.');
        $xml=file_get_contents($file->getPathname(),false,null,0,WxrDiscovery::MAX_BYTES+1);if($xml===false)throw new \RuntimeException('Cannot read WXR upload.');
        $v=$this->center->scan($p['id'],(int)$rev,$xml);$this->record($r,$v['status'],$p['id']);return $this->response($v);
    }
}
