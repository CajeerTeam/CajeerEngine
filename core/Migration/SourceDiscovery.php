<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Security\{OutboundHttpTransport,PublicHttpClient};

/** Read-only discovery. No source content, credentials or personal data enter reports. */
final readonly class SourceDiscovery
{
    public function __construct(private OutboundHttpTransport $http = new PublicHttpClient()) {}

    public static function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value)) throw new \InvalidArgumentException('Invalid database identifier.');
        return $value;
    }

    public static function dbConfig(array $config): array
    {
        $host = strtolower(trim((string)($config['host'] ?? '')));
        $port = $config['port'] ?? 3306;
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !is_int($port) || $port<1 || $port>65535) throw new \InvalidArgumentException('Use an explicitly allowed IPv4 database address and port.');
        $allowed = array_map('trim', explode(',', (string)getenv('MIGRATION_DB_ALLOWED_ENDPOINTS')));
        if (!in_array($host.':'.$port, $allowed, true)) throw new \InvalidArgumentException('Database endpoint is not allowed by the operator.');
        $database = self::identifier((string)($config['database'] ?? ''));
        $prefix = (string)($config['prefix'] ?? '');
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $prefix)) throw new \InvalidArgumentException('Invalid table prefix.');
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        if (!in_array($charset, ['utf8mb4','utf8','cp1251'], true)) throw new \InvalidArgumentException('Unsupported database connection charset.');
        return compact('host','port','database','prefix','charset');
    }

    public static function restConfig(array $config): array
    {
        $url = rtrim((string)($config['url'] ?? ''), '/'); $p = parse_url($url);
        if (!filter_var($url,FILTER_VALIDATE_URL) || ($p['scheme']??'')!=='https' || ($p['port']??443)!==443 || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || strlen($url)>1000) throw new \InvalidArgumentException('Use a HTTPS WordPress site URL without query or credentials.');
        $host = strtolower((string)$p['host']);
        if(filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)&&!PublicHttpClient::isPublicIp(trim($host,'[]')))throw new \InvalidArgumentException('Non-public WordPress destination denied.');
        $allowed = array_map('trim', explode(',', strtolower((string)getenv('MIGRATION_HTTP_ALLOWED_HOSTS'))));
        if (!in_array($host,$allowed,true)) throw new \InvalidArgumentException('WordPress host is not allowed by the operator.');
        return ['url'=>$url];
    }

    /** Read-only WordPress attachment metadata for an operator-approved media import. */
    public function wordpressMediaMetadata(string $source,array $config,#[\SensitiveParameter] array $secret):array
    {
        if($source==='wordpress-db'){
            return $this->database($source,self::dbConfig($config),$secret,static fn($pdo,$report)=>WordPressMediaMetadata::fromDatabase($pdo,(string)$report['details']['prefix']));
        }
        if($source!=='wordpress-rest')throw new \InvalidArgumentException('WordPress media metadata requires a WordPress source.');
        $config=self::restConfig($config);$headers=['Accept'=>'application/json'];
        if(($secret['username']??'')!=='')$headers['Authorization']='Basic '.base64_encode($secret['username'].':'.$secret['password']);
        $assets=[];$featured=[];$started=microtime(true);
        foreach(['media','posts','pages'] as $kind){
            for($page=1;$page<=6;$page++){
                if(microtime(true)-$started>25)throw new \RuntimeException('WordPress media metadata scan timed out.');
                $fields=$kind==='media'?'id,source_url':'id,featured_media';
                $url=$config['url'].'/wp-json/wp/v2/'.$kind.'?per_page=100&page='.$page.'&_fields='.$fields;
                [$status,$body]=$this->http->request($url,'GET',$headers,'',2*1024*1024);
                if($page>1&&$status===400&&in_array(json_decode($body,true)['code']??'',['rest_post_invalid_page_number','rest_user_invalid_page_number'],true))break;
                if($status!==200)throw new \RuntimeException('WordPress media metadata collection unavailable (HTTP '.$status.').');
                $rows=json_decode($body,true,64,JSON_THROW_ON_ERROR);
                if(!is_array($rows)||!array_is_list($rows))throw new \RuntimeException('Invalid WordPress media metadata response.');
                foreach($rows as $r){$id=$r['id']??null;if(!is_int($id)||$id<1)continue;
                    if($kind==='media'&&is_string($r['source_url']??null))$assets[(string)$id]=$r['source_url'];
                    elseif(is_int($r['featured_media']??null)&&$r['featured_media']>0)$featured[(string)$id]=(string)$r['featured_media'];}
                if(count($assets)>500||count($featured)>500)throw new \RuntimeException('WordPress REST media metadata exceeds 500 records.');
                if(count($rows)<100)break;
            }
        }
        return ['attachments'=>$assets,'featured'=>$featured];
    }

    public function scan(string $source,array $config, #[\SensitiveParameter] array $secret): array
    {
        return match($source) {
            'dle-db','wordpress-db'=>$this->database($source,self::dbConfig($config),$secret),
            'wordpress-rest'=>$this->rest(self::restConfig($config),$secret),
            default=>throw new \InvalidArgumentException('Upload a WXR file to scan this project.'),
        };
    }

    private function database(string $source,array $c, #[\SensitiveParameter] array $secret, ?callable $reader=null): array
    {
        if (!in_array('mysql',\PDO::getAvailableDrivers(),true)) throw new \RuntimeException('Discovery requires pdo_mysql.');
        try {
            $options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>5,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::MYSQL_ATTR_LOCAL_INFILE=>false,\PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
            $ca=(string)getenv('MIGRATION_DB_TLS_CA');
            if($ca!==''){if(!is_readable($ca))throw new \RuntimeException('Operator database TLS CA is unavailable.');$options[\PDO::MYSQL_ATTR_SSL_CA]=$ca;$options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
            elseif(PublicHttpClient::isPublicIp($c['host']))throw new \RuntimeException('Public database endpoints require MIGRATION_DB_TLS_CA; use a private tunnel otherwise.');
            $pdo=new \PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset='.$c['charset'], $secret['username'], $secret['password'], $options);
            if($ca!==''&&($pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(\PDO::FETCH_NUM)[1]??'')==='')throw new \RuntimeException('Source database did not negotiate TLS.');
            $engine=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if(stripos($engine,'mariadb')!==false)$pdo->exec('SET SESSION max_statement_time=5');else $pdo->exec('SET SESSION max_execution_time=5000');
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            try { $report=$this->inspectDatabase($source,$pdo,$c['prefix'],$c['charset']); return $reader ? $reader($pdo,$report) : $report; }
            finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
        } catch (\PDOException) { throw new \RuntimeException('Source database connection or read failed. Verify read-only grants, schema and connectivity.'); }
    }

    /** Only fixed SELECTs and validated metadata-derived identifiers. */
    public function inspectDatabase(string $source,\PDO $pdo,string $prefix='',string $charset='utf8mb4'): array
    {
        $wp=$source==='wordpress-db';
        $tables=$pdo->query('SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION LIMIT 10001')->fetchAll(\PDO::FETCH_ASSOC);
        if(count($tables)>10000)throw new \RuntimeException('Source schema exceeds discovery limit (10000 columns).');
        $schema=[];$encodings=[];
        foreach($tables as $r){$schema[$r['TABLE_NAME']][]=$r['COLUMN_NAME'];if($r['CHARACTER_SET_NAME'])$encodings[$r['CHARACTER_SET_NAME']]=true;}
        $required=$wp?['posts','postmeta','options']:['post','category'];
        $anchor=$wp?'posts':'post';
        if($prefix===''){
            $candidates=[];foreach(array_keys($schema) as $t){if(!str_ends_with($t,$anchor))continue;$p=substr($t,0,-strlen($anchor));if($p!==''&&!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$p))continue;if(array_reduce($required,fn($ok,$s)=>$ok&&isset($schema[$p.$s]),true))$candidates[]=$p;}
            if(count($candidates)!==1)throw new \RuntimeException('Source CMS prefix is ambiguous or not detected. Specify the correct prefix.');$prefix=$candidates[0];
        }
        foreach($required as $s)if(!isset($schema[$prefix.$s]))throw new \RuntimeException('Required source CMS tables are missing.');
        $table=fn(string $s)=>'`'.self::identifier($prefix.$s).'`';
        $has=fn(string $s,string $col)=>in_array($col,$schema[$prefix.$s]??[],true);
        $count=function(string $s,string $where='')use($pdo,$schema,$prefix,$table):?int{return isset($schema[$prefix.$s])?(int)$pdo->query('SELECT COUNT(*) FROM '.$table($s).$where)->fetchColumn():null;};
        $groups=function(string $s,string $col)use($pdo,$table,$has):array{if(!$has($s,$col))return []; $out=[];foreach($pdo->query('SELECT `'.$col.'` AS k, COUNT(*) AS n FROM '.$table($s).' GROUP BY `'.$col.'` ORDER BY n DESC LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC) as $r)$out[(string)$r['k']]=(int)$r['n'];return $out;};
        $fields=[];$warnings=[];$types=[];$statuses=[];$version=null;
        if($wp){
            if(!$has('posts','post_type')||!$has('posts','post_status'))throw new \RuntimeException('WordPress posts schema is not compatible.');
            $types=$groups('posts','post_type');$statuses=$groups('posts','post_status');
            $counts=['posts'=>$count('posts'," WHERE post_type='post'"),'pages'=>$count('posts'," WHERE post_type='page'"),'media'=>$count('posts'," WHERE post_type='attachment'"),'users'=>$count('users'),'comments'=>$count('comments'),'categories'=>$has('term_taxonomy','taxonomy')?$count('term_taxonomy'," WHERE taxonomy='category'"):null,'tags'=>$has('term_taxonomy','taxonomy')?$count('term_taxonomy'," WHERE taxonomy='post_tag'"):null];
            if($has('postmeta','meta_key'))$fields=array_map('strval',$pdo->query('SELECT DISTINCT meta_key FROM '.$table('postmeta').' WHERE meta_key IS NOT NULL ORDER BY meta_key LIMIT 101')->fetchAll(\PDO::FETCH_COLUMN));
            if($has('options','option_name')&&$has('options','option_value')){$q=$pdo->query("SELECT option_value FROM ".$table('options')." WHERE option_name='db_version' LIMIT 1");$v=$q->fetchColumn();$version=is_string($v)&&ctype_digit($v)?'db_schema:'.$v:null;}
            $warnings[]='WordPress database schema revision is not the exact application version. Multisite users may be shared; plugins and custom types require mapping.';
        }else{
            if(!$has('post','id')||!$has('post','title')||!$has('post','full_story'))throw new \RuntimeException('DLE post schema is not compatible.');
            $counts=['posts'=>$count('post'),'pages'=>$count('static'),'categories'=>$count('category'),'users'=>$count('users'),'comments'=>$count('comments'),'media'=>null,'tags'=>$has('tags','tag')?(int)$pdo->query('SELECT COUNT(DISTINCT tag) FROM '.$table('tags'))->fetchColumn():null];$statuses=$groups('post','approve');
            if($has('post','xfields')){foreach($pdo->query('SELECT LEFT(xfields,16384) FROM '.$table('post')." WHERE xfields IS NOT NULL AND xfields <> '' LIMIT 1000")->fetchAll(\PDO::FETCH_COLUMN) as $v){foreach(explode('||',(string)$v) as $pair){$name=explode('|',$pair,2)[0];if(preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$name))$fields[$name]=$name;if(count($fields)>100)break 2;}}$fields=array_values($fields);}
            $fields=array_values(array_unique(array_merge($fields,['category_names','category_tree','dle_views','dle_rating','dle_seo_title','dle_seo_description','dle_seo_keywords'])));
            $warnings[]='DLE extra fields are sampled from up to 1000 news records. Files and exact CMS version cannot be determined from database tables alone.';
        }
        return self::report($source,$counts,$types,$statuses,$fields,$warnings,['prefix'=>$prefix,'connection_charset'=>$charset,'table_encodings'=>array_keys($encodings),'version'=>$version,'tables'=>array_values(array_filter(array_keys($schema),fn($t)=>str_starts_with($t,$prefix))),'columns'=>array_intersect_key($schema,array_flip(array_map(fn($s)=>$prefix.$s,$wp?['posts','postmeta','users','usermeta','terms','term_taxonomy','term_relationships']:['post','static','category','users','comments'])))]);
    }

    /** Read actual source records using the same read-only connection and endpoint policy as discovery. */
    public function inventory(string $source,array $config, #[\SensitiveParameter] array $secret): array
    {
        if ($source==='wordpress-rest') return (new PlanningSource($this->http))->rest(self::restConfig($config),$secret);
        if (!in_array($source,['dle-db','wordpress-db'],true)) throw new \InvalidArgumentException('Unsupported planning source.');
        $c=self::dbConfig($config);
        // MySQL converts character columns from their stored charset to UTF-8.
        $c['charset']='utf8mb4';
        return $this->database($source,$c,$secret,fn($pdo,$report)=>(new PlanningSource($this->http))->database($source,$pdo,$report));
    }

    public function rest(array $config, #[\SensitiveParameter] array $secret): array
    {
        $url=$config['url'].'/wp-json/wp/v2/'; $headers=['Accept'=>'application/json'];
        if(($secret['username']??'')!=='')$headers['Authorization']='Basic '.base64_encode($secret['username'].':'.$secret['password']);
        $started=microtime(true);$counts=[];$warnings=['REST reports only entities visible to the supplied credentials; private content, plugin data and media bytes are not guaranteed.'];$partial=[];
        foreach(['posts'=>'posts','pages'=>'pages','media'=>'media','categories'=>'categories','tags'=>'tags','users'=>'users','comments'=>'comments'] as $key=>$endpoint){
            if(microtime(true)-$started>20){$counts[$key]=null;$warnings[]=$key.': scan time budget reached.';continue;}
            $requestUrl=$url.$endpoint.'?per_page=100&_fields=id';
            $response=$this->http instanceof PublicHttpClient ? $this->http->requestWithHeaders($requestUrl,'GET',$headers,'',524288) : $this->http->request($requestUrl,'GET',$headers,'',524288);
            [$status,$body]=$response;$total=$response[2]['x-wp-total']??null;
            if($status!==200){$counts[$key]=null;$warnings[]=$key.': HTTP '.$status; if($key==='posts')throw new \RuntimeException('WordPress posts endpoint returned HTTP '.$status.'.');continue;}
            $rows=json_decode($body,true);if(!is_array($rows)||!array_is_list($rows))throw new \RuntimeException('Invalid WordPress REST collection.');
            foreach($rows as $row)if(!is_array($row)||!is_int($row['id']??null))throw new \RuntimeException('Invalid WordPress REST record.');
            $counts[$key]=count($rows);if(is_string($total)&&ctype_digit($total)&&strlen($total)<16&&(int)$total>=count($rows)){$counts[$key]=(int)$total;continue;}if(count($rows)===100){$partial[]=$key;$warnings[]=$key.': at least 100 visible entities (first page only).';}
        }
        return self::report('wordpress-rest',$counts,[],[],[],$warnings,['lower_bound_counts'=>$partial,'version'=>null]);
    }

    public static function report(string $source,array $counts,array $types,array $statuses,array $fields,array $warnings,array $details=[]): array
    {
        if(str_starts_with($source,'wordpress-'))$fields=array_values(array_unique(array_merge(['wp_date_gmt','wp_protected','wp_parent'],$fields)));
        $fields=array_values(array_filter($fields,fn($v)=>is_string($v)&&strlen($v)<=200&&preg_match('//u',$v)));
        if(count($fields)>100)$warnings[]='Field list capped at 100.';
        $compat=[];foreach($counts as $k=>$v)$compat[$k]=$v===null?'unavailable':(in_array($k,['posts','pages','categories','tags'],true)?'mapping_required':'later_migration_stage');
        return ['source'=>$source,'scanned_at'=>gmdate(DATE_ATOM),'counts'=>$counts,'types'=>$types,'statuses'=>$statuses,'fields'=>array_slice($fields,0,100),'compatibility'=>$compat,'warnings'=>$warnings,'details'=>$details,'target_modified'=>false];
    }
}
