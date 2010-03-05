<?php

error_reporting(E_ALL);

require_once('Config.inc.php');
require_once('lib/Test.classes.php');
require_once('lib/CodeListing.classes.php');


#CodeListing::classes('Orm');

# public static function t($name, $func, $params, $against_func_str /* $result is passed into this function */, $hide_stack_trace = false)
Test::init();
SSql::query("DELETE FROM person WHERE dna_seq = 'abcxyz' AND surname = 'Bloggs' AND given_name = 'Joe'");
new Person(array('dna_seq'    => 'abcxyz',
                 'surname'    => 'Bloggs',
                 'given_name' => 'Joe'));

#SSql::$debug = true;
foreach (Orm::load('Person') as $o) {
        print_r($o);
        print_r($o->getRelsByClass('Office'));
        #print_r($habitation = $o->getRelsByClasses('Home', 'LivesIn'));
        print_r($habitation = $o->getRelsByClasses('Home', 'LivesIn', 'Person -> (LivesIn) -> Home'));
        print_r($habitation->classHome[0]);
        print_r($habitation->classLivesIn[0]);
}

#OrmSqlCache::save();

# suggested tests
# 1. Orm::load
# 2. OrmClass->getRelsByClasses() for direct relation
# 3. OrmClass->getRelsByClasses() for indirect relation
# 4. OrmClass->getRelsByClasses() for multiple relations
# 5. OrmClass->getRelsByClasses() with chain
# 6. OrmClass->getRelsByClasses() with chains
# 7. OrmClass->getRelsByClass() without and with chain

# TODO
# consider adding an incrementing integer to alias so that multiple passes of the same rule are ok
# this would allow the system more flexibility for a person (keep the routing part crippled though)
# would need to update the SQL generation stage, and the Object creation stage
# after more thought; could result in output of objects that are the same as already constructed ones
# to mitigate this we could scan previously constructed objects of the same class type for equal keys (as they've just been constructed anyway)
# and merely point to the same object -- use Orm->equals() to establish this
# started coding this and stopped... see $seen_results
# -- stopped cos i'll have to have numbers in all aliases and i'm not so sure i want that!

if (sizeof($history = SSql::getQueryHistory()) >0)
        printf("Number of SQL queries: %d, SQL Query History\n%s", sizeof($history), print_r($history, true));

printf("Peak memory usage: %.3fMB\n", xdebug_peak_memory_usage() / pow(2, 20));
#Test::summary('OrmClass');
Orm::routeFromChain(NULL, 'Person -> (LivesIn) -> Home -> (Neighbours) -> Home -> (Neighbours) -> Home', array((object) array('class' => 'LivesIn')));

?>
