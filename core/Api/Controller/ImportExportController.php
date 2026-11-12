<?php

declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final readonly class ImportExportController
{
    public function __construct(private ImportExportService $service) {}

    public function exports(Request $request,array $parameters): JsonResponse
    { return new JsonResponse(['data'=>$this->service->exports()]); }
    public function export(Request $request,array $parameters): JsonResponse
    {
        $p=$this->json($request);return new JsonResponse(['data'=>$this->service->createExport($p['sections']??[],$p['filename']??null)],201);
    }
    public function diff(Request $request,array $parameters): JsonResponse
    {
        $p=$this->json($request);return new JsonResponse(['data'=>$this->service->diff($this->file($p),$p)]);
    }
    public function import(Request $request,array $parameters): JsonResponse
    {
        $p=$this->json($request);return new JsonResponse(['data'=>$this->service->request($this->file($p),$p)],202);
    }
    public function upload(Request $request,array $parameters): JsonResponse
    {
        $file=$request->files->get('file');
        if(!is_object($file)||!$file->isValid())throw new \InvalidArgumentException('Valid multipart file required');
        return new JsonResponse(['data'=>$this->service->upload($file->getPathname(),$file->getClientOriginalName())],201);
    }
    public function download(Request $request,array $parameters): BinaryFileResponse
    {
        $file=(string)$parameters['file'];$path=$this->service->downloadPath($file);
        $response=new BinaryFileResponse($path);$response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT,$file);
        $response->headers->set('Content-Type',str_ends_with($file,'.zip')?'application/zip':'application/json');
        $response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Content-Type-Options','nosniff');return $response;
    }
    public function requests(Request $request,array $parameters): JsonResponse
    { return new JsonResponse(['data'=>$this->service->requests()]); }
    private function file(array $p): string
    {
        $file=$p['file']??'';if(!is_string($file)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.(?:zip|json)$/D',$file))throw new \InvalidArgumentException('Provide a package basename');return $file;
    }
    private function json(Request $request): array
    { $p=$request->attributes->get('json');return is_array($p)?$p:json_decode($request->getContent()?:'{}',true,64,JSON_THROW_ON_ERROR); }
}
