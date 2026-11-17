<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Integration\SecretVault;

final class MigrationFactory
{
    public static function create(string $root,DatabaseManager $db,ContentTypeRepository $types,ContentEntryRepository $entries,UserRepository $users):MigrationCenter
    {
        $planner=new MigrationPlanner($types,$entries,$users,$db);
        return new MigrationCenter($root,new SecretVault((string)(getenv('INTEGRATIONS_KEY')?:getenv('APP_KEY')?:'')),planner:$planner,dle:new DleImporter($root,$db,$types,$entries,$planner),media:new MediaMigration($db,$entries,new \CajeerEngine\Media\MediaRepository($root,new \CajeerEngine\Storage\StorageManager($root,new \CajeerEngine\Runtime\ConfigRepository($root)),$db),$types),seo:new SeoMigration($root,$db,$entries),execution:new MigrationExecution($root,$db,$entries,$types,$planner));
    }
}
