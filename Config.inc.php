<?php

date_default_timezone_set('UTC');

require_once('lib/Exception.classes.php');
require_once('Orm.classes.php');
require_once('lib/SSql.classes.php');
require_once('lib/Node.classes.php');

SSql::connect('MySQL', 'orm_test', 'localhost', '3306', 'orm_test', 'xCwNxDB8BUwTSy5A', NULL, FALSE);
#SSql::connect('SQLite', 'orm.db'); # note that SQLite support is broken :P

require_once('Schema.inc.php');
require_once('OrmSqlCache.class.php');

?>
