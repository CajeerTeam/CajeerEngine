<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Search\SearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CajeerEngine\Search\PublicSearchPage;

final readonly class SearchController
{
    public function __construct(private SearchService $search)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse($this->results($request),200,['Cache-Control'=>'no-store']);
    }

    public function publicIndex(Request $request,array $parameters): JsonResponse
    {
        return new JsonResponse($this->results($request),200,['Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex, follow']);
    }

    public function publicPage(Request $request,array $parameters): Response
    {
        $result=$this->results($request);
        return new Response(PublicSearchPage::render($result),200,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex, follow']);
    }

    private function results(Request $request): array
    {
        $params=$request->query->all();
        foreach(['q','index','type','locale','limit','offset'] as $key)if(isset($params[$key])&&!is_string($params[$key])&&!is_int($params[$key]))throw new \InvalidArgumentException('Invalid search parameter: '.$key);
        $query=(string)($params['q']??'');$index=(string)($params['index']??'');
        if($index!==''&&$index!=='content')throw new \InvalidArgumentException('Only the content index is available');
        $limit=$this->number($params['limit']??20,1,100);$offset=$this->number($params['offset']??0,0,100000);
        try{$result=$this->search->search($query,$index!==''?$index:null,$limit,$offset,(string)($params['type']??''),(string)($params['locale']??''));}
        catch(\InvalidArgumentException $e){throw $e;}
        catch(\Throwable $e){throw new \CajeerEngine\Search\SearchUnavailable('Search catalog unavailable.',0,$e);}
        foreach(['type','locale'] as $key)$result['meta']['facets'][$key]=(object)$result['meta']['facets'][$key];
        return $result;
    }

    private function number(string|int $value,int $min,int $max): int
    {
        if(!preg_match('/^[0-9]{1,6}$/D',(string)$value)||(int)$value<$min||(int)$value>$max)throw new \InvalidArgumentException('Search pagination out of range');
        return (int)$value;
    }

    /** @param array<string, mixed> $parameters */
    public function reindex(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->search->reindex()]);
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->search->diagnostics()]);
    }
}
