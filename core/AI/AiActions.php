<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentEntryValidator;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Persistent, explicitly approved AI actions. Model output can PREPARE, never execute. */
final readonly class AiActions
{
    private const TTL = 86400;
    private const TYPES = [
        'cms_action_create_draft' => 'content:write',
        'cms_action_update_seo' => 'content:write',
        'cms_action_support_status' => 'content:write',
    ];

    public function __construct(
        private DatabaseManager $db,
        private ContentTypeRepository $types,
        private ContentEntryRepository $entries,
        private AiSupportCenter $support,
        private AuditLogger $audit,
    ) {}

    public static function allowed(string $action, array $scopes): bool
    {
        $scope = self::TYPES[$action] ?? null;
        return $scope !== null && (in_array('*', $scopes, true) || in_array($scope, $scopes, true));
    }

    /** The tool definitions are strict and intentionally expose no arbitrary document/data object. */
    public function definitions(array $scopes): array
    {
        $specs = [
            'cms_action_create_draft' => ['Create a CMS draft proposal. Does NOT publish or write until the operator approves.', [
                'type'=>['type'=>'string'], 'title'=>['type'=>'string'], 'slug'=>['type'=>'string'],
                'locale'=>['type'=>'string'], 'content_field'=>['type'=>'string'], 'content'=>['type'=>'string'],
            ]],
            'cms_action_update_seo' => ['Propose a SEO title or description change for an existing CMS entry. Approval required.', [
                'type'=>['type'=>'string'], 'id'=>['type'=>'string'], 'field'=>['type'=>'string','enum'=>['seo_title','meta_description']], 'value'=>['type'=>'string'],
            ]],
            'cms_action_support_status' => ['Propose to change a support ticket status. Operator approval required.', [
                'ticket_id'=>['type'=>'string'], 'status'=>['type'=>'string','enum'=>['open','waiting','resolved','closed']],
            ]],
        ];
        $definitions = [];
        foreach ($specs as $name => [$description, $properties]) {
            if (!self::allowed($name, $scopes)) continue;
            $definitions[] = ['type'=>'function','name'=>$name,'description'=>$description,'strict'=>true,
                'parameters'=>['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false]];
        }
        return $definitions;
    }

    public function catalog(array $scopes): array
    {
        return array_map(static fn(array $spec): array => [
            'name'=>$spec['name'], 'description'=>$spec['description'], 'scope'=>'content:write', 'requires_approval'=>true,
        ], $this->definitions($scopes));
    }

    private static function string(array $args, string $key, int $max, bool $empty = false): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value) || (!$empty && trim($value) === '')) {
            throw new \InvalidArgumentException('Invalid action field: '.$key);
        }
        return trim($value);
    }

    private static function handle(string $value): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $value)) throw new \InvalidArgumentException('Invalid content type.');
        return $value;
    }

    private static function uuid(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
            throw new \InvalidArgumentException('Invalid identifier.');
        }
        return $value;
    }

    private static function randomId(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
    }

    /** @return array<string,mixed> */
    public function prepare(string $action, array $arguments, string $userId, array $scopes): array
    {
        if (!self::allowed($action, $scopes)) throw new \DomainException('AI action is not permitted.');
        $definitions = $this->definitions($scopes);
        $spec = null;
        foreach ($definitions as $definition) if ($definition['name'] === $action) $spec = $definition;
        if ($spec === null) throw new \DomainException('Unknown AI action.');
        $keys = array_keys($spec['parameters']['properties']);
        if (array_diff(array_keys($arguments), $keys) || array_diff($keys, array_keys($arguments))) {
            throw new \InvalidArgumentException('Incorrect action arguments.');
        }
        $payload = []; $expected = null; $before = null;
        if ($action === 'cms_action_create_draft') {
            $type = self::handle(self::string($arguments, 'type', 100));
            $contentType = $this->types->find($type) ?? throw new \DomainException('Unknown content type.');
            $title = self::string($arguments,'title',180);
            $slug = self::string($arguments,'slug',180, true);
            $locale = self::string($arguments,'locale',16);
            $field = self::string($arguments,'content_field',64);
            $body = self::string($arguments,'content',12000);
            $fields = $contentType['fields'] ?? [];
            $valid = false; $fieldType = null;
            foreach ($fields as $definition) {
                if (($definition['handle'] ?? null) === $field && in_array($definition['type'] ?? '', ['text','textarea','richtext'], true)) { $valid = true; $fieldType = $definition['type']; break; }
            }
            if (!$valid) throw new \InvalidArgumentException('Content field is not a writable text field.');
            if ($fieldType === 'richtext') {
                // Generated markup is untrusted; save safe paragraphs rather than executable HTML.
                $paragraphs = preg_split('/\n\s*\n/u', $body) ?: [$body];
                $body = implode("\n", array_map(static fn(string $paragraph): string =>
                    '<p>'.nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false).'</p>', $paragraphs));
            }
            if ($slug === '') $slug = $title;
            $payload = ['type'=>$type, 'title'=>$title, 'slug'=>$slug, 'locale'=>$locale, 'status'=>'draft', 'data'=>[$field=>$body]];
            // Validate the actual CMS schema BEFORE a proposal can be queued.
            $payload = array_merge($payload, (new ContentEntryValidator($contentType))->normalizeForCreate($payload));
            $payload['status'] = 'draft';
        } elseif ($action === 'cms_action_update_seo') {
            $type = self::handle(self::string($arguments,'type',100));
            $id = self::uuid(self::string($arguments,'id',36));
            $field = self::string($arguments,'field',30);
            if (!in_array($field, ['seo_title','meta_description'], true)) throw new \InvalidArgumentException('Unsupported SEO field.');
            $value = self::string($arguments,'value',300);
            $characters = function_exists('mb_strlen') ? mb_strlen($value,'UTF-8') : preg_match_all('/./us',$value);
            if ($characters > ($field === 'seo_title' ? 70 : 160) || $value !== strip_tags($value)) throw new \InvalidArgumentException('SEO value is unsafe or too long.');
            $entry = $this->entries->findById($type,$id) ?? throw new \DomainException('Content entry not found.');
            $expected = (int)$entry['revision'];
            $before = ['field'=>$field,'value'=>(string)($entry['data'][$field] ?? ''),'status'=>$entry['status'], 'revision'=>$expected];
            $payload = ['type'=>$type,'id'=>$id,'field'=>$field,'value'=>$value];
        } else {
            $id = self::uuid(self::string($arguments,'ticket_id',36));
            $status = self::string($arguments,'status',12);
            if (!in_array($status,['open','waiting','resolved','closed'],true)) throw new \InvalidArgumentException('Invalid ticket status.');
            $ticket = $this->support->agentView($id);
            $expected = (int)$ticket['revision'];
            $before = ['status'=>$ticket['status'], 'priority'=>$ticket['priority'], 'revision'=>$expected];
            $payload = ['ticket_id'=>$id,'status'=>$status];
        }
        $id = self::randomId(); $now = time();
        $data = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $prior = json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->db->transaction(function(PDO $pdo) use ($id, $userId, $action, $data, $prior, $expected, $now): void {
            $limit = $pdo->prepare('SELECT COUNT(*) FROM ce_ai_actions WHERE user_id = ? AND state = ? AND expires_at >= ?');
            $limit->execute([$userId,'pending',$now]);
            if ((int)$limit->fetchColumn() >= 30) throw new \DomainException('Too many pending AI actions; approve or reject existing proposals.');
            $stmt = $pdo->prepare('INSERT INTO ce_ai_actions (id, user_id, action_name, payload_json, before_json, expected_revision, state, created_at, expires_at, decided_at, result_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)');
            $stmt->execute([$id,$userId,$action,$data,$prior,$expected,'pending',$now,$now+self::TTL]);
        });
        $this->audit->record('ai.action.prepared', $userId, ['id'=>$id,'action'=>$action]);
        return $this->get($userId,$id);
    }

    public function pending(string $userId): array
    {
        $query = $this->db->connection()->prepare('SELECT * FROM ce_ai_actions WHERE user_id = ? AND state = ? AND expires_at >= ? ORDER BY created_at DESC, id DESC LIMIT 30');
        $query->execute([$userId,'pending',time()]);
        return array_map([self::class,'map'], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(string $userId, string $id): array
    {
        $stmt = $this->db->connection()->prepare('SELECT * FROM ce_ai_actions WHERE id = ? AND user_id = ?');
        $stmt->execute([self::uuid($id),$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \DomainException('Action not found.');
        return self::map($row);
    }

    private static function map(array $row): array
    {
        return ['id'=>$row['id'],'action'=>$row['action_name'], 'payload'=>json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR),
            'before'=>json_decode($row['before_json'],true,512,JSON_THROW_ON_ERROR), 'expected_revision'=>$row['expected_revision'] === null ? null : (int)$row['expected_revision'],
            'state'=>$row['state'],'created_at'=>(int)$row['created_at'],'expires_at'=>(int)$row['expires_at'],
            'decided_at'=>$row['decided_at'] === null ? null : (int)$row['decided_at'],
            'result'=>$row['result_json'] === null ? null : json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR)];
    }

    public function reject(string $userId, string $id): array
    {
        $action = $this->db->transaction(function(PDO $pdo) use ($userId, $id): array {
            $stmt = $pdo->prepare('UPDATE ce_ai_actions SET state = ?, decided_at = ? WHERE id = ? AND user_id = ? AND state = ? AND expires_at >= ?');
            $stmt->execute(['rejected',time(),self::uuid($id),$userId,'pending',time()]);
            if ($stmt->rowCount() !== 1) throw new \DomainException('Action is missing, expired or already decided.');
            return $this->get($userId,$id);
        });
        $this->audit->record('ai.action.rejected',$userId,['id'=>$id,'action'=>$action['action']]);
        return $action;
    }

    public function approve(string $userId, string $id, array $scopes, bool $confirmPublished): array
    {
        $action = $this->db->transaction(function(PDO $pdo) use ($userId, $id, $scopes, $confirmPublished): array {
            $row = $this->get($userId,$id);
            if ($row['state'] !== 'pending' || $row['expires_at'] < time()) throw new \DomainException('Action is missing, expired or already decided.');
            if (!self::allowed($row['action'], $scopes)) throw new \DomainException('AI action permission was revoked.');
            // CAS reserves the action; a DB rollback reverses this reservation and the CMS write.
            $claim = $pdo->prepare('UPDATE ce_ai_actions SET state = ? WHERE id = ? AND user_id = ? AND state = ? AND expires_at >= ?');
            $claim->execute(['running',self::uuid($id),$userId,'pending',time()]);
            if ($claim->rowCount() !== 1) throw new \DomainException('This action was already decided.');
            $payload = $row['payload'];
            if ($row['action'] === 'cms_action_create_draft') {
                if ($this->entries->storage() !== 'database') throw new \DomainException('Atomic draft approval requires database-backed CMS content.');
                // Status is fixed server-side; the model cannot publish content.
                $created = $this->entries->create($payload['type'], ['title'=>$payload['title'],'slug'=>$payload['slug'],
                    'locale'=>$payload['locale'],'data'=>$payload['data'],'status'=>'draft']);
                $result = ['type'=>$payload['type'],'id'=>$created['id'],'revision'=>$created['revision'],'status'=>$created['status']];
            } elseif ($row['action'] === 'cms_action_update_seo') {
                if ($this->entries->storage() !== 'database') throw new \DomainException('Atomic SEO approval requires database-backed CMS content.');
                $existing = $this->entries->findById($payload['type'],$payload['id']) ?? throw new \DomainException('Entry disappeared.');
                if ((int)$existing['revision'] !== $row['expected_revision'] || (string)($existing['data'][$payload['field']] ?? '') !== (string)($row['before']['value'] ?? '')) {
                    throw new \DomainException('Content changed since the action was prepared.');
                }
                if ($existing['status'] === 'published' && !$confirmPublished) throw new \DomainException('Published content requires an extra confirmation.');
                $data = $existing['data']; $data[$payload['field']] = $payload['value'];
                $updated = $this->entries->update($payload['type'],$payload['id'],['expected_revision'=>(int)$row['expected_revision'],'data'=>$data], 'ai_editor')
                    ?? throw new \DomainException('Entry disappeared.');
                $result = ['type'=>$payload['type'],'id'=>$updated['id'],'revision'=>$updated['revision'],'status'=>$updated['status']];
            } else {
                $updated = $this->support->update($payload['ticket_id'],$userId,['expected_revision'=>(int)$row['expected_revision'],'status'=>$payload['status']]);
                $result = ['ticket_id'=>$updated['id'],'revision'=>$updated['revision'],'status'=>$updated['status']];
            }
            $save = $pdo->prepare('UPDATE ce_ai_actions SET state = ?, decided_at = ?, result_json = ? WHERE id = ? AND state = ?');
            $save->execute(['applied',time(),json_encode($result,JSON_THROW_ON_ERROR),$id,'running']);
            if ($save->rowCount() !== 1) throw new \RuntimeException('Action result could not be committed.');
            return $this->get($userId,$id);
        });
        $this->audit->record('ai.action.applied',$userId,['id'=>$id,'action'=>$action['action']]);
        return $action;
    }
}
