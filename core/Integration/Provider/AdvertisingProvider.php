<?php

declare(strict_types=1);
namespace CajeerEngine\Integration\Provider;

use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\PublicHttpClient;

/** Fixed advertising endpoints. Only the trusted, consent-checked ServerEvents worker may invoke these. */
final readonly class AdvertisingProvider implements ProviderInterface
{
    public const TIKTOK_URL='https://business-api.tiktok.com/open_api/v1.3/event/track/';
    public function __construct(private bool $meta,private OutboundHttpTransport $http=new PublicHttpClient()) {}
    public function id(): string {return $this->meta?'meta-capi':'tiktok-events';}
    public function descriptor(): array {return ['id'=>$this->id(),'name'=>$this->meta?'Meta Conversions API':'TikTok Events API','secret_label'=>'Advertising API access token','secret_required'=>true,'operations'=>['server-event']];}
    public function configuration(array $config): array
    {
        $url=$config['url']??null;
        if($this->meta){
            if(array_keys($config)!==['url']||!is_string($url)||!preg_match('~^https://graph\.facebook\.com/v(?:2[2-9]|3[0-9])\.0/[1-9][0-9]{5,24}/events$~D',$url))throw new \InvalidArgumentException('Use https://graph.facebook.com/v25.0/PIXEL_ID/events');
            return ['url'=>$url];
        }
        if(count($config)!==2||$url!==self::TIKTOK_URL||!is_string($config['pixel_id']??null)||!preg_match('/^[A-Z0-9]{10,40}$/D',$config['pixel_id']))throw new \InvalidArgumentException('TikTok Events API requires the fixed endpoint and Pixel ID');
        return ['url'=>$url,'pixel_id'=>$config['pixel_id']];
    }
    public function validateSecret(#[\SensitiveParameter] string $secret): void
    {
        if(strlen($secret)<10||strlen($secret)>4096||preg_match('/[\x00-\x20\x7f]/',$secret))throw new \InvalidArgumentException('Invalid advertising API token');
    }
    public static function supports(string $provider,string $name): bool
    {
        return in_array($provider,['meta-capi','tiktok-events'],true)&&in_array($name,['purchase','generate_lead','form_submit','sign_up'],true);
    }
    public function execute(array $config,#[\SensitiveParameter] string $secret,string $event,array|\stdClass $payload,string $eventId): int
    {
        $config=$this->configuration($config);$this->validateSecret($secret);$p=(array)$payload;
        $name=str_starts_with($event,'analytics.')?substr($event,10):'';
        if(!self::supports($this->id(),$name)||!preg_match('/^[a-f0-9]{32}$/D',$eventId)||!is_int($p['event_time']??null)||$p['event_time']>time()+60||$p['event_time']<time()-900||!is_string($p['email_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['email_sha256'])||!is_string($p['source_url']??null)||!is_string($p['user_agent']??null)||$p['user_agent']===''||!is_numeric($p['value']??null)||!is_string($p['currency']??null))throw new \InvalidArgumentException('Invalid advertising conversion');
        $properties=['value'=>$p['value'],'currency'=>$p['currency']];
        if($this->meta){
            $mapping=['purchase'=>'Purchase','generate_lead'=>'Lead','form_submit'=>'Contact','sign_up'=>'CompleteRegistration'];
            $body=['data'=>[['event_name'=>$mapping[$name],'event_time'=>$p['event_time'],'event_id'=>$eventId,'action_source'=>'website','event_source_url'=>$p['source_url'],'user_data'=>['em'=>[$p['email_sha256']],'client_user_agent'=>$p['user_agent']],'custom_data'=>$properties]]];
            $headers=['Authorization'=>'Bearer '.$secret,'Content-Type'=>'application/json'];
        }else{
            $mapping=['purchase'=>'Purchase','generate_lead'=>'Contact','form_submit'=>'SubmitForm','sign_up'=>'CompleteRegistration'];
            $body=['event_source'=>'web','event_source_id'=>$config['pixel_id'],'data'=>[['event'=>$mapping[$name],'event_time'=>$p['event_time'],'event_id'=>$eventId,'user'=>['email'=>$p['email_sha256'],'user_agent'=>$p['user_agent']],'page'=>['url'=>$p['source_url']],'properties'=>$properties]]];
            $headers=['Access-Token'=>$secret,'Content-Type'=>'application/json'];
        }
        [$status,$response]=$this->http->request($config['url'],'POST',$headers,json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),65536);
        if($status<200||$status>=300)return $status;
        // Both APIs can reject a request in an HTTP 200 response. Never return success without an API receipt.
        $result=json_decode($response,true,32);
        if(!is_array($result))return 502;
        return ($this->meta?(!isset($result['error'])&&is_int($result['events_received']??null)&&$result['events_received']>=1):(($result['code']??null)===0))?$status:422;
    }
}
