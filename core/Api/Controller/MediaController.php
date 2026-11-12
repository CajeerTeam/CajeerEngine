<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Media\MediaRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class MediaController
{
    public function __construct(private MediaRepository $media)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        $filter=['q'=>(string)$request->query->get('q',''),'mime'=>(string)$request->query->get('mime',''),'visibility'=>(string)$request->query->get('visibility','')];
        if($request->query->has('folder'))$filter['folder']=(string)$request->query->get('folder');
        $page=$this->media->page($filter,$request->query->getInt('limit',50),$request->query->getInt('offset',0));
        $page['meta']['folders']=$this->media->folders();
        return new JsonResponse($page);
    }

    /** @param array<string, mixed> $parameters */
    public function show(Request $request, array $parameters): JsonResponse
    {
        $item = $this->media->find((string) ($parameters['id'] ?? ''));
        if ($item === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $item]);
    }

    /** @param array<string, mixed> $parameters */
    public function store(Request $request, array $parameters): JsonResponse
    {
        $file = $request->files->get('file');
        if (is_object($file)) {
            $item = $this->media->createFromUploadedFile($file, $request->request->all());
        } else {
            $item = $this->media->createFromBase64($this->json($request));
        }

        return new JsonResponse(['data' => $item], Response::HTTP_CREATED);
    }

    public function update(Request $request,array $parameters): JsonResponse
    {
        $id=(string)$parameters['id'];if(!$this->media->find($id))return $this->notFound();
        return new JsonResponse(['data'=>$this->media->update($id,$this->json($request))]);
    }
    public function replace(Request $request,array $parameters): JsonResponse
    {
        $id=(string)$parameters['id'];if(!$this->media->find($id))return $this->notFound();
        $file=$request->files->get('file');if(!is_object($file))throw new \InvalidArgumentException('File required');
        return new JsonResponse(['data'=>$this->media->replaceUploaded($id,$file)]);
    }
    public function variants(Request $request,array $parameters): JsonResponse
    {
        $id=(string)$parameters['id'];if(!$this->media->find($id))return $this->notFound();
        return new JsonResponse(['data'=>$this->media->regenerate($id)]);
    }
    public function usage(Request $request,array $parameters): JsonResponse
    {
        $id=(string)$parameters['id'];if(!$this->media->find($id))return $this->notFound();
        return new JsonResponse(['data'=>$this->media->usage($id)]);
    }

    public function download(Request $request,array $parameters): Response
    {
        $id=(string)$parameters['id'];if(!$this->media->find($id))return $this->notFound();
        return $this->stream($this->media->download($id,$request->query->get('variant'),$request->query->get('version'),false),$request->query->get('variant')!==null);
    }
    public function sign(Request $request,array $parameters): JsonResponse
    {
        $id=(string)$parameters['id'];if(!$this->media->find($id))return $this->notFound();
        return new JsonResponse(['data'=>$this->media->signedUrl($id,$request->query->getInt('ttl',300),$request->query->get('variant'),$request->query->get('version'),$request->query->getBoolean('s3',false))]);
    }
    public function signedDownload(Request $request,array $parameters): Response
    {
        try{$file=$this->media->signedDownload((string)$parameters['id'],$request->query->getInt('expires'),(string)$request->query->get('signature',''),$request->query->get('variant'),$request->query->get('version'));return $this->stream($file);}
        catch(\Throwable){return new JsonResponse(['error'=>['code'=>'invalid_media_link','message'=>'Invalid or expired media link']],403);}
    }
    private function stream(array $file,bool $preview=false): StreamedResponse
    {
        // Keep the verified inode open: a concurrent atomic replacement cannot change delivered bytes.
        $handle=fopen($file['local_path'],'rb');if(!$handle)throw new \RuntimeException('Cannot open media');
        $hash=hash_init('sha256');hash_update_stream($hash,$handle);if(!hash_equals($file['checksum'],hash_final($hash))||!rewind($handle)){fclose($handle);throw new \RuntimeException('Media changed before delivery');}
        $filename=preg_replace('/[^a-zA-Z0-9._-]/','-',(string)$file['filename']);
        $inline=$preview&&in_array($file['mime_type'],['image/jpeg','image/png','image/webp'],true);
        return new StreamedResponse(static function()use($handle){try{fpassthru($handle);}finally{fclose($handle);}},200,['Content-Type'=>$inline?$file['mime_type']:'application/octet-stream','Content-Disposition'=>($inline?'inline':'attachment').'; filename="'.$filename.'"','Content-Length'=>(string)$file['size'],'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    /** @param array<string, mixed> $parameters */
    public function delete(Request $request, array $parameters): JsonResponse
    {
        if (!$this->media->delete((string) ($parameters['id'] ?? ''))) {
            return $this->notFound();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->media->diagnostics($request->query->getBoolean('verify',false))]);
    }

    /** @return array<string, mixed> */
    private function json(Request $request, bool $strict = true): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) {
            return $parsed;
        }

        $body = trim($request->getContent());
        if ($body === '') {
            return $strict ? [] : $request->request->all();
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Тело запроса должно быть валидным JSON: ' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body должен быть объектом.');
        }

        return $payload;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'media_not_found',
                'message' => 'Media-файл не найден.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }
}
