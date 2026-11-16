<?php
declare(strict_types=1);
return ['enabled'=>filter_var(getenv('ANALYTICS_REPORTING_ENABLED')===false?'true':getenv('ANALYTICS_REPORTING_ENABLED'),FILTER_VALIDATE_BOOLEAN)];
