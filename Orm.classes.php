<?php
 /******************************************************************************
 * Copyright (c) 2009, Adam Piper
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the <organization> nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY Adam Piper ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL Adam Piper BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 ******************************************************************************/

# Due to technology constraints we can't implement variable overriding (as redeclaration is undetectable)
#
# loading:
#         care only about property names
#         names higher up the heirarchy (where the top is Orm) take absolute precidence over those lower down
#         this enables efficient retrieval of the values from the db
#
# saving:
#         care about all properties and their values
#         names higher up the heirarchy (where the top is Orm) take absolute precidence over those lower down
#         this enables us to insert/update to multiple tables with all values set
#
# properties in the immediate decendant should be global and supreme??
# better idea; just don't include the immediate descendant in our collection
#
#
# examples of tree heirarchies for overriding variables:
#
# 1) Orm -> OrmClass -> A -> B
# 2)                      -> C -> D
# 3)                           -> E
# 4)                      -> F -> G
#
# What if?
# A=2, B=NULL, C=NULL, D=5, E=NULL, F=7, G=2
# 1) load:    select A table
#    save:    update A table, insert B table
# 2) load:    INVALID
#    save:    INVALID
# 3) load:    INVALID
#    save:    update A table, insert C table, insert E table
# 4) load:    select A table, select F table, select G table
#    save:    update A table, udpate F table, update G table


##################
# notes for docs
# Inherits is a special relationship
# Inherits is a one-way rule
# anchor alias (0)
# user defined keys; CLASS::CLASS_keys
# relationships don't have to be instanciable unless you want to store variables in them. classes do.
# when extending Orm* classes and further extending the __construct() method, a useful line to stick in the top is: $args = func_get_args(); call_user_func_array(array('parent', '__construct'), $args); unset($args);
#
# db structure:
#     class as table
#     fk rels as alias__key__key
#     fk rel vars as alias__var__var
#
##################


# TODO: move from array_shift() to array_pop() for performance; array_reverse() once is acceptable to facilitate this

# TODO: implement Orm::load() and OrmClass->save()
#                            possibly with getHeirarchy() or maybe just with getProperties() ? getHeirarchy is cheaper. put in OrmClass.
#                            getHeirarchy may be cheaper but getProperties is used everywhere else. centralise code. use getProperties.

# TODO: make sure that thrown exceptions in private methods of Orm and direct descendants are of type OrmException, not of OrmInputException, cos it's us doing things wrong.

# TODO: create Orm::getClasses('Blog') style thingie to load up all blogs
# TODO: OrmClass->getRelations('AuthoredBy') -- remember that at this level we only think about aliases in the chain. relation names are lower down the abstraction.

# TODO: get rid of OrmClass->getId() -- superceded by Orm->getKeyValues('Blog') (should probably move this to OrmClass) ------- it's not superceded because "id" is special; can have many ids for a heirarchy of objects

# TODO: tracking of classes and their relations for saving will likely require storage of an object (need to store "loaded_from_db" and "added_to" for each r/ship)

# TODO: neaten up the classes, yo.


class OrmException                       extends SPFException {}
class OrmInputException                  extends OrmException {}
class OrmRelationshipResolutionException extends OrmException {}

abstract class Orm
{
        const DEFAULT_NAME           = '-';
        const RELATIONSHIP_INHERITS  = 'Inherits';
        const ALIAS_ANCHOR           = '0';
        const AUTO_PROPERTY_ID       = 'id';
        const ORM_CLASS_SQL_CACHE    = 'OrmSqlCache';
        const ORM_CLASS_CLASS        = 'OrmClass';
        const ORM_CLASS_RELATIONSHIP = 'OrmRelationship';

        private static $schemas = array();
        private $loaded_from_db = array();

        /** Setting: should Orm generate its own IDs? **/
        public static $use_guids          = true;

        /** Setting: should Orm manage its own foreign key constraints (including cascading deletes/updated)? **/
        public static $manage_constraints = true;

        protected function setLoadedFromDb($class)
        {
                $this->loaded_from_db[] = $class;
        }

        protected function getLoadedFromDb($class)
        {
                return in_array($class, $this->loaded_from_db);
        }

        # Start of schema-related stuff
        public final static function getNames()
        {
                return array_keys(self::$schemas);
        }

        public final static function setup($schema, $ssql_name = NULL, $name = NULL)
        {
                if (self::validName(self::DEFAULT_NAME))
                        throw new OrmException('The default_name constant must NOT be a valid name!');

                self::name($name);
                if (!SSql::validName($ssql_name))
                        throw new OrmInputException('Invalid SSql Name specified, please ensure you create an SSql connection prior to setting up Orm');

                self::$schemas[$name] = (object) array(
                        'rules'            => self::parseRules($schema),
                        'ssql_name'        => $ssql_name,
                        'classes'          => array(),
                        'relationships'    => array(),
                        'irelationships'   => array(),
                        'sql_result_cache' => array()
                );

                foreach (self::$schemas[$name]->rules as $rule) {
                        self::validClassName($rule->input);
                        self::validClassName($rule->output);
                        self::validClassName($rule->relationship);


                        if ($rule->relationship == self::RELATIONSHIP_INHERITS)
                                throw new OrmInputException('The relationship "%s" is reserved', self::RELATIONSHIP_INHERITS);

                        if (class_exists($rule->input)) {
                                if (!is_subclass_of($rule->input, self::ORM_CLASS_CLASS))
                                        throw new OrmInputException('The specified class %s exists but is not a subclass of OrmClass', $rule->input );

                                self::$schemas[$name]->classes[] = $rule->input;
                        }

                        if (class_exists($rule->output)) {
                                if (!is_subclass_of($rule->output, self::ORM_CLASS_CLASS))
                                        throw new OrmInputException('The specified class %s exists but is not a subclass of OrmClass', $rule->output);

                                self::$schemas[$name]->classes[] = $rule->output;
                        }

                        if (in_array($rule->relationship, self::$schemas[$name]->relationships))
                                throw new OrmInputException('Relationships must be unique; "%s" is already registered', $rule->relationship);

                        self::$schemas[$name]->relationships[] = $rule->relationship;
                        if (class_exists($rule->relationship)) {
                                if (!is_subclass_of($rule->relationship, self::ORM_CLASS_RELATIONSHIP))
                                        throw new OrmInputException('The specified class %s exists but is not a subclass of OrmRelationship', $rule->relationship);

                                self::$schemas[$name]->irelationships[] = $rule->relationship;
                        }
                }

                self::$schemas[$name]->classes       = array_unique(self::$schemas[$name]->classes);
                # TODO: wtf?
                self::$schemas[$name]->relationships = self::$schemas[$name]->relationships;

        }

        # returns true only if registered and instanciable
        protected static function isRegisteredClass($name, $class)
        {
                self::name($name);
                return in_array($class, self::$schemas[$name]->classes);
        }

        # returns true only if registered and instanciable
        protected static function isRegisteredIRelationship($name, $relationship)
        {
                self::name($name);
                return in_array($relationship, self::$schemas[$name]->irelationships);
        }

        # returns true only if registered
        protected static function isRegisteredRelationship($name, $relationship)
        {
                self::name($name);
                return in_array($relationship, self::$schemas[$name]->relationships);
        }

        public static function validName($name)
        {
                if(!is_null($name) && (!is_string($name) || preg_match('#[^a-zA-Z0-9_]#', $name) || preg_match('#^[^a-zA-Z]#', $name)))
                        return false;

                return true;
        }

        # for use when the arrays are going to be accessed directly, both checks the name and substitues the default value in where required
        private static function name(&$name)
        {
                if(!self::validName($name))
                        throw new OrmInputException('$name must be a string of alphanumerics and underscores, starting with a letter. You passed '.$name);

                if (is_null($name))
                        $name = self::DEFAULT_NAME;
        }

        public static final function getSchema($name = NULL)
        {
                self::name($name);
                if (!isset(self::$schemas[$name]))
                        throw new OrmInputException('Invalid Orm name specified');

                return self::$schemas[$name];
        }

        # DO NOT USE self::name() BELOW THIS POINT!

        public static final function getRules($name = NULL)
        {
                return self::getSchema($name)->rules;
        }


        public static final function getSSqlName($name = NULL)
        {
                return self::getSchema($name)->ssql_name;
        }

        # Takes SQL and checked for a cached result to avoid re-running SQL
        protected static final function getSqlResult($name, $sql)
        {
                $hash = sha1($sql);

                $s = self::getSchema($name);
                $ssql_name = self::getSSqlName($name);

                if (isset($s->sql_result_cache[$hash])) {
                        $result = $s->sql_result_cache[$hash];
                        SSql::rewind($result, $ssql_name); # rewind the result automatically
                        return $result;
                }

                # we didn't find the cached result so execute, add to cache and return the result
                $s->sql_result_cache[$hash] = ($result = SSql::query($sql, $ssql_name));
                return $result;
        }

        # TODO: rship resolution function; need to throw OrmRelationshipResolutionException if cannot resolve. need to use this on user-facing stuff
        protected static final function resolveRelationship($name, $class_a, $class_b)
        {
                # TODO: change func name of Orm::name() to something like Orm::internalizeName()
                $possibilities = array();
                foreach (self::getSchema($name) as $rule) {
                        if       ($rule->input == $class_a && $rule->output == $class_b) {
                                $possiblities[] = $rule;
                        } elseif ($rule->input == $class_b && $rule->output == $class_a) {
                                $possiblities[] = $rule;
                        }
                }

                switch (sizeof($possiblities)) {
                        case 0:
                                throw new OrmRelationshipResolutionException('Failed to resolve relationship: no matches found');
                        case 1:
                                return reset($possibilities);
                        default:
                                foreach ($possiblities as $r)
                                        $matches[] = sprintf('%s to %s as %s', $r->input, $r->output, $r->relationship);
                                throw new OrmRelationshipResolutionException('Failed to resolve relationship: multiple matches found: %s', implode(', ', $matches));
                }
        }

        # End of schema-related stuff


        # Important note:
        # It's crucial to the sytem as a whole that these regexps do not allow underscores in class names, and double underscores in property names

        private final static function validClassName($name)
        {
                # a-z, 0-9. Must start with a capitalised letter
                return !preg_match('#[^A-Za-z0-9]|^[^A-Z]#', $name);
        }

        private final static function validPropertyName($name)
        {
                # a-z, 0-9 and underscore. Must start with a letter. Cannot contain two underscores in a row
                return !preg_match('#[^a-z0-9_]|^[^a-z]|__#', $name);
        }

        # not sure of the point of this one......
        private final static function validDbName($name)
        {
                return !preg_match('#[^a-z_]|^[^a-z]|[^a-z]|__$#', $name);
        }

        private final static function testClassName($name) {
                if (!self::validClassName($name))
                        throw new OrmInputException('Class '.$name.' does not adhere to class name rules');
        }

        private final static function testPropertyName($name) {
                if (!self::validPropertyName($name))
                        throw new OrmInputException('Property '.$name.' does not adhere to property name rules');
        }

        # TODO: does not belong in Orm* classes -- MOVE!
        protected static function debugDump($var)
        {
                ob_start();
                var_dump($var);
                $output = ob_get_contents();
                ob_end_clean();
                return $output;
        }

        /* DEPRECATED
        # returns an associative array of the form class => properties, where properties is an array of strings
        private static final function getHeirarchy($class) ####### DEPRECATED
        {
                ### DEPRECATED #################################################
                throw new OrmException('The method getHeirarchy is DEPRECATED');
                ### DEPRECATED #################################################

                if (!is_subclass_of($class, __CLASS__))
                        throw new OrmInputException('Given class is not a subclass of '.__CLASS__);

                # first create the array
                $hclass = $class;
                do
                        $heirarchy[$hclass] = array();
                while(get_parent_class($hclass = get_parent_class($hclass)) != __CLASS__);

                $class_reflection = new ReflectionClass($class);
                foreach($class_reflection->getProperties() as $pr) {
                        if ($pr->isStatic() || $pr->isPrivate())
                                continue;

                        $source_class  = $pr->getDeclaringClass()->getName();
                        $property_name = $pr->getName();

                        if ($property_name == 'id') throw new OrmInputException('Class "%s" may not contain the reserved property "%s"', $source_class, $property_name);

                        self::testClassName($source_class);
                        self::testPropertyName($property_name);

                        if (!isset($heirarchy[$source_class]))
                                $heirarchy[$source_class] = array();

                        $heirarchy[$source_class][] = $property_name;
                }
                return $heirarchy;
        }
        */

        # returns an array of property names for a given class. If the second argument is TRUE then the class heirarchy is flattened
        public static final function getProperties($class, $flat = false)
        {
                if (!is_subclass_of($class, __CLASS__))
                        throw new OrmInputException('Given class is not a subclass of '.__CLASS__);

                # first create the array
                $properties = array();

                $class_reflection = new ReflectionClass($class);
                do {
                        foreach ($class_reflection->getProperties() as $pr) {
                                if ($pr->isStatic() || $pr->isPrivate())
                                        continue;

                                $source_class  = $pr->getDeclaringClass()->getName();
                                if ($class_reflection->getName() == $class && !$flat && $source_class != $class)
                                        continue;
                                elseif ($source_class != $class_reflection->getName())
                                        continue;

                                $property_name = $pr->getName();

                                if ($property_name == self::AUTO_PROPERTY_ID)
                                        throw new OrmInputException('Class "%s" may not contain the reserved property "%s"', $source_class, $property_name);

                                self::testClassName($source_class);
                                self::testPropertyName($property_name);

                                $properties[] = $property_name;
                        }
                } while (($class_reflection = $class_reflection->getParentClass())     && # get the parent
                          $class_reflection->getName() != self::ORM_CLASS_CLASS        && # check that we're not hitting the top
                          $class_reflection->getName() != self::ORM_CLASS_RELATIONSHIP && # check that we're not hitting the top
                          !$class_reflection->isInstantiable());                          # all abstract classes get their properties merged

                return $properties;
        }

        public static final function classToDbName($class_name)
        {
                if (!is_string($class_name))
                        throw new OrmInputException('Must pass a string');

                self::testClassName($class_name);
                $sql_name = '';
                for($i = 0; $i < strlen($class_name); $i++) {
                        $c = substr($class_name, $i, 1);
                        if (preg_match('#[A-Z]#', $c)) { # uppercase => new word
                                if ($i != 0) $sql_name .= '_'; # don't want a leading underscore
                                $sql_name .= strtolower($c);
                        } else {        # lowercase
                                $sql_name .= $c;
                        }
                }
                return $sql_name; # 'BaseReport' -> 'base_report'
        }

        public static final function propertyToDbName($property_name)
        {
                if (!is_string($property_name))
                        throw new OrmInputException('Must pass a string');

                self::testPropertyName($property_name);

                # return the input
                return $property_name;
        }

        public static final function dbToClassName($db_name)
        {
                return str_replace(' ', '', ucwords(str_replace('_', ' ', $db_name)));
        }

        public static final function dbToPropertyName($db_name) {
                return $db_name;
        }

        /** function returns custom keys specified on a per-class basis by CLASSNAME::CLASSNAME_keys
            or defaults to array('id') **/
        public static final function getKeys($class)
        {
                if (!is_subclass_of($class, __CLASS__))
                        throw new OrmInputException('Supply a subclass of '.__CLASS__);

                $const = sprintf('%s::%s_keys', $class, $class);
                if (!defined($const))
                        return array(self::AUTO_PROPERTY_ID);

                $keys = array_unique(explode(',', constant($const)));

                foreach ($keys as $key)
                        if (!in_array($key, self::getProperties($class)))
                                throw new OrmInputException("$key is not a member of class $class");

                return $keys;
        }

        #################################### SQL Construction ###################

        /** Returns a quoted and suitably escaped variable **/
        public static final function sqlVar($name, $var, $type = NULL)
        {
                switch ($type) {
                        case NULL:
                        case 'NULL':
                                break;
                        case 'string':
                                $var = (string) $var;
                                break;
                        case 'int':
                                $var = (int)    $var;
                                break;
                        case 'float':
                                $var = (float)  $var;
                                break;
                        default:
                                throw new OrmInputException('Unrecognised type requirement: %s. Valid options are: NULL, string, int, float.', $type);
                }

                if (is_string ($var))               return (string) sprintf("'%s'", SSql::escape($var, self::getSSqlName($name)));
                if (is_int($var) || is_float($var)) return (string) SSql::escape($var, self::getSSqlName($name));
                if (is_null   ($var))               return (string) 'NULL';

                throw new OrmInputException('Cannot insert variables of type %s into the database', gettype($var));
        }

        private static final function parseRules($code)
        {
                # parse mappings I to O as A
                $rules = array();
                foreach (preg_split("#\r?\n#", $code) as $line) {
                        if (!preg_match('#^(?<input>\w+)\s+to\s+(?<output>\w+)\s+as\s+(?<relationship>\w+)(\s+(?<options>.+))?$#', $line, $match))
                                continue;

                        $rule = new stdClass();

                        $rule->input   = $match['input' ];
                        $rule->output  = $match['output'];
                        $rule->relationship   = $match['relationship' ];
                        if (!isset($match['options']))
                                $rule->options = array();
                        else
                                $rule->options = preg_split('#\s+#', $match['options']);

                        $rules[] = $rule;
                }

                return $rules;
        }

        private static final function isMatch($rule, $spec)
        {
                if (!is_object($rule) || !isset($rule->input) || !isset($rule->output) || !isset($rule->relationship))
                        throw new OrmException('Rule must be an object with properties input, output, relationship all set');

                if (is_string($spec))
                        return $spec == $rule->input || $spec == $rule->output;

                # essentially an "or"
                if (is_array($spec))
                        foreach ($spec as $s)
                                if (self::isMatch($s))
                                        return true;

                if (!is_object($spec))
                        throw new OrmException('Specs must be either strings, objects or arrays of objects');

                if (isset($spec->input) && $spec->input != $rule->input)
                        return false;

                if (isset($spec->output) && $spec->output != $rule->output)
                        return false;

                if (isset($spec->alias) && $spec->alias != $rule->relationship)
                        return false;

                return true;
        }

        # find a route from class A to class B
        # note that this function has some advanced functionality in the rule_matches variable; it is possible to set "waypoints"
        #         either as a string (in which case they match to either input or output rules) or as an object where any or all
        #         of input, output, alias may be stipulated
        private static final function routeRecurse($input_spec, $output_spec, $name, $waypoint_specs = array(), &$paths = array(), $path = array())
        {
                # if the path is empty, we should begin with rules that specify our input class in their ->input or ->output
                if (sizeof($path) == 0) {
                        foreach (self::getRules($name) as $rule) {
                                if (self::isMatch($rule, $input_spec))
                                        self::routeRecurse($input_spec, $output_spec, $name, &$waypoint_specs, $paths, array($rule));
                        }

                        # we have our valid paths by this point

                        # if no valid paths are found return NULL
                        if (sizeof($paths) == 0)
                                return NULL;

                        # sort paths by size and return the shortest
                        usort($paths, array('self', 'sizeCompare'));
                        return array_pop($paths);
                }

                # else we're on course; examine the final entry on our path as test
                $test = $path[sizeof($path)-1];

                # if $test matches our $output_spec, iterate over the waypoint_specs to make sure we matched everything
                if (self::isMatch($test, $output_spec)) {
                        foreach ($waypoint_specs as $spec) {
                                $match = true; # empty rules must match
                                if (is_array($spec))
                                        $spec = (object) $spec;

                                foreach ($path as $rule) {
                                        $match = self::isMatch($rule, $spec);

                                        if ($match)
                                                break;
                                }

                                if (!$match) # a required match was not found
                                        return NULL;
                        }

                        # by this point we have a valid path that matches all requirements
                        $paths[] = $path;

                        return NULL;
                }

                # else find another rule whose ->input or ->output matches test->input or ->output and does not already exist in our path, and recurse
                foreach (self::getRules($name) as $rule) {
                        if (($test->input  == $rule->input   ||
                             $test->input  == $rule->output  ||
                             $test->output == $rule->input   ||
                             $test->output == $rule->output) &&
                             !in_array($rule, $path))
                                self::routeRecurse($input_spec, $output_spec, $name, &$waypoint_specs, $paths, array_merge($path, array($rule)));
                }

                return NULL;
        }

        private static final function sizeCompare(&$a, &$b)
        {
                $diff = sizeof($b) - sizeof($a);

                if ($diff != 0)
                        return $diff;

                # sizes are the same, so let's compare number of unique classes used instead
                # should we just compare unique classes from the outset? -- NO; this gives complex routes that loop over the same class an unfair advantage

                $ca = array();
                foreach ($a as $rule) {
                        $ca[] = $rule->input;
                        $ca[] = $rule->output;
                }

                $cb = array();
                foreach ($b as $rule) {
                        $cb[] = $rule->input;
                        $cb[] = $rule->output;
                }

                return sizeof(array_unique($cb)) - sizeof(array_unique($ca));
        }

        private static final function comboRecurse($items, &$combos, $used = array())
        {
                if (sizeof($items) == sizeof($used)) {
                        if (!is_array($combos))
                                $combos = array();

                        #if (!in_array(array_reverse($used), $combos))
                        $combos[] = $used;
                        return NULL;
                }

                foreach ($items as $item)
                        if (!in_array($item, $used))
                                self::comboRecurse($items, $combos, array_merge($used, array($item)));
        }

        private static final function anchorInput(array &$combo, $key, array $input) { # method deprecated following removal of anchoring concept. TODO: remove.
                $combo = array_merge($input, $combo);
        }

        private static final function getRoute($name, array $combos, array $stipulations)
        {
                # calculate routes, select the shortest full route
                $routes = array();
                foreach ($combos as $i => $combo) {
                        # we're going from point A to point B
                        $a = array_shift($combo); # first item is "A"
                        $b = array_pop($combo);   # last item is "B"

                        if ($route = self::routeRecurse($a, $b, $name, array_merge($combo, $stipulations)))
                                $routes[$i] = $route;
                }

                usort($routes, array('self', 'sizeCompare'));
                return array_pop($routes);
        }

        private static final function objectify(&$var)
        {
                if (!is_object($var))
                        $var = (object) $var;
        }

        private static final function rshipRespecify(&$class, $key, $name)
        {
                if (self::isRegisteredIRelationship($name, $class))
                        $class = (object) array('relationship' => $class);
        }

        private static final function rshipRespecifyArray(&$array, $key, $name)
        {
                array_walk($array, array('self', 'rshipRespecify'), $name);
        }

        # Constructs SQL from Class-based specifications of input/output
        #
        #         destinations: object->class  = "classname"
        #                             [->input  = (spec)]
        #                             [->output = true]
        #
        #         NOTE: the first destination is treated as an anchor; it is the point from which paths are calculated
        #               the rest of the destinations are randomized and the shortest path is chosen
        #
        #         waypoints are of a DIFFERENT FORM than destinations
        #
        #         waypoints refer to rules you require the route to use, they can be either rule objects of the form:
        #                       object->input
        #                       object->output
        #                       object->alias
        #               where you may specify any or all of the properties to match against
        #               or you may merely pass a string, this will be treated as:
        #                       (object->input == string || object->output == string)
        #               i.e. you may match against either the input or output of a rule, thereby stipulating a table as a waypoint
        #
        protected static final function generateSqlComponents($name, array $destinations, $chain = NULL)
        {
                # ensure variable formatting
                array_walk($destinations, array('self', 'objectify'));

                # turn the destinations into specifications
                $class_dests = array();
                $waypoints   = array();
                foreach ($destinations as $dest) {
                        if (!isset($dest->class))
                                throw new OrmInputException('Must set "class" variable for destinations');

                        if (self::isRegisteredClass($name, $dest->class) && isset($dest->alias) && $dest->alias != self::ALIAS_ANCHOR)
                                $waypoints[] = (object) array('alias' => $dest->alias); # these are infered waypoints

                        if (self::isRegisteredIRelationship($name, $dest->class))
                                $waypoints[] = (object) array('alias' => $dest->class);
                        else
                                $class_dests[] = $dest;
                }

                # now we need to cater for the situation where only one table is being selected from
                $dest_count = sizeof($class_dests);
                if ($dest_count == 0)
                        throw new OrmInputException('Must supply one or more destinations');


                if ($chain) {
                        $route = self::routeFromChain($name, $chain, $destinations);
                } else {
                        $tail = $class_dests;
                        $head = array_shift($tail);

                        if ($dest_count == 1) {
                                # create a fake route for SQL generation consisting of a single rule (with a hackish invalid alias name)
                                $route = array((object) array('input'         => $head->class,
                                                              'output'        => $head->class,
                                                              'relationship'  => self::ALIAS_ANCHOR));

                        } else {
                                # generate list of destination classes
                                #       anchor first, scramble rest

                                $list = array();
                                foreach ($tail as $dest)
                                        $list[] = $dest->class;

                                self::comboRecurse($list, $combos);

                                array_walk($combos, array('self', 'anchorInput'), array($head->class));

                                # make sure that relationships are properly specified as waypoints
                                array_walk($combos, array('self', 'rshipRespecifyArray'), $name);

                                if (!$route = self::getRoute($name, $combos, $waypoints))
                                        throw new OrmInputException("Could not calculate a valid route for this combination of destinations");
                        }
                }

                return self::sqlFromRoute($name, $route, $destinations, $class_dests);
        }

        public static function routeFromChain($name, $chain, $destinations)
        {
                # check that all destinations are accounted-for
                foreach ($destinations as $dest) {
                        if (self::isRegisteredIRelationship($name, $dest->class)) {
                                if (!preg_match(sprintf('#\(%s\)#', $dest->class), $chain))
                                        throw new OrmInputException('Destination "%s" is not accounted for in the given chain', $dest->class);

                        } else if (self::isRegisteredClass($name, $dest->class))  {
                                if (!preg_match(sprintf('#(^| )%s( |$)#', $dest->class), $chain))
                                        throw new OrmInputException('Destination "%s" is not accounted for in the given chain', $dest->class);
                        } else {
                                throw new OrmInputException('The destination "%s" is registered neither as a Class or Relationship', $dest->class);
                        }
                }

                $route = array();
                while (preg_match('#^([^ ]+) -> \(([^\)]+)\) -> ([^ ]+)#', $chain, $m)) {
                        list($subchain, $c1, $r, $c2) = $m;
                        $added = FALSE;
                        foreach (self::getRules($name) as $rule)
                                if ($rule->relationship == $r && (($rule->input == $c1 && $rule->output == $c2) || ($rule->input == $c2 && $rule->output == $c1))) {
                                        $added = TRUE;
                                        $route[] = $rule;
                                }

                        if (!$added)
                                throw new OrmInputException('No defined rule was found to match the subchain "%s"', $subchain);

                        $chain = substr($chain, strlen(sprintf('%s -> (%s) -> ', $m[1], $m[2])));
                }

                return $route;
        }

        private static function sqlFromRoute($name, $route, $destinations, $class_dests)
        {
                $chain = array();

                $last_rule = (object) array('input'         => NULL,
                                            'output'        => reset($class_dests)->class,
                                            'relationship'  => self::ALIAS_ANCHOR);

                # by this point we have a valid route, started off with a dummy rule that has an alias of "0"

                # prepare the Nodes array
                $inputs = $outputs = array($last_rule->output);
                foreach ($route as $rule) {
                        $inputs [] = $rule->input;
                        $outputs[] = $rule->output;
                }
                # just in case the final rule is backwards; we don't want it turning up in nodes
                $inputs[]  = $rule->input;
                $outputs[] = $rule->input;

                $inputs  = array_unique($inputs);
                $outputs = array_unique($outputs);
                $nodes  = array_diff($outputs, $inputs);
                unset($inputs, $outputs);

                # Classify dests for optimisations
                $used = $non_key_inputs = $outputs = array();
                foreach ($class_dests as $dest) {
                        if (!isset($dest->alias))
                                $alias = NULL;

                        $o = (object) array('class' => $dest->class, 'alias' => $alias);

                        $used[] = $o;

                        if (isset($dest->input))
                                foreach ($dest->input as $key => $val)
                                        if (self::isRegisteredClass($name, $dest->class) && !in_array($key, self::getKeys($dest->class)))
                                                $non_key_inputs[] = $o;

                        if (isset($dest->output))
                                $outputs[] = $o;
                }

# TODO: when we are asked to output a relationship but the table in which it's stored is not in our destinations (and is not a hinge we visit): throw
                if (empty($outputs))
                        throw new OrmInputException('No Class outputs given');

                /*if (sizeof($route) > 1)*/
                if (sizeof($class_dests) > 1)
                        $route[] = NULL; # add an extra element so that the chain continues long enough to evaluate the final table

                $select = $from = $where = $seen_results = array();
                foreach ($route as $rule) {
                        ### reset vars to avoid confusion
                        $current_table    =
                        $linked_class     =
                        $linked_table     =
                        $result_of        =
                        $link             =
                        $link_stored_here =
                                NULL;

                        if (is_null($rule)) { # special rule for final link in chain
                                if ($current_class == $last_rule->input) {
                                        $current_class = $last_rule->output;
                                        $linked_class  = $last_rule->input;
                                        $link_stored_here = false;
                                } else {
                                        $current_class = $last_rule->input;
                                        $linked_class  = $last_rule->output;
                                        $link_stored_here = true;
                                }
                        } else if ($rule->input  == $last_rule->input || $rule->input  == $last_rule->output) {
                                $current_class = $rule->input;
                                $linked_class = $rule->output;
                                $link_stored_here = true;
                        } else if ($rule->output == $last_rule->input || $rule->output == $last_rule->output) {
                                $current_class = $rule->output;
                                $linked_class = $rule->input;
                                $link_stored_here = false;
                        }
                        $result_of = $last_rule->relationship;
                        if (!is_null($rule))
                                $link = $rule->relationship;

                        # variables for use in loop:
                        if ($link)
                                $link_sql = self::classToDbName($link);

                        $curr_unused_node = (in_array($current_class, $nodes) && !(in_array((object) array('class' => $current_class, 'alias' => NULL),       $used) ||
                                                                                   in_array((object) array('class' => $current_class, 'alias' => $result_of), $used)));
                        $link_unused_node = (in_array($linked_class, $nodes)  && !(in_array((object) array('class' => $linked_class,  'alias' => NULL),       $used) ||
                                                                                   in_array((object) array('class' => $linked_class,  'alias' => $link),      $used)));

                        # this portion of the code tries to avoid pulling in more tables by designating inputs that can be specified over a relationship
                        # i.e. by adding contraints to tables we already pull in for joins
                        $input_as_rel_keys = false;
                        if (!$curr_unused_node && in_array($current_class, $nodes) &&
                            !(in_array((object) array('class' => $current_class, 'alias' => NULL),       $outputs)        ||
                              in_array((object) array('class' => $current_class, 'alias' => $result_of), $outputs))       &&
                            !(in_array((object) array('class' => $current_class, 'alias' => NULL),       $non_key_inputs) ||
                              in_array((object) array('class' => $current_class, 'alias' => $result_of), $non_key_inputs))) {
                                $curr_unused_node = true;
                                $input_as_rel_keys = true;
                        }
                        if (!$link_unused_node && in_array($linked_class, $nodes) &&
                            !(in_array((object) array('class' => $linked_class, 'alias' => NULL),       $outputs)   ||
                              in_array((object) array('class' => $linked_class, 'alias' => $result_of), $outputs))  &&
                            !(in_array((object) array('class' => $linked_class, 'alias' => NULL),  $non_key_inputs) ||
                              in_array((object) array('class' => $linked_class, 'alias' => $link), $non_key_inputs))) {
                                $link_unused_node = true;
                        }

                        $result_of_sql   = $result_of == self::ALIAS_ANCHOR? $result_of : self::classToDbName($result_of);
                        $current_table   = self::classToDbName($current_class);
                        /*$current_alias   = self::isRegisteredClass($name, $current_class)? sprintf("%s__%s", $current_table, $result_of_sql) : $current_table;*/

                        # result increments
                        if (!isset($seen_results[$result_of_sql])) {
                                $seen_results[$result_of_sql] = 0;
                                $result_of_incremented = sprintf('%s__0', $result_of_sql);
                        } else {
                                $result_of_incremented = sprintf('%s__%s', $result_of_sql, ++$seen_results[$result_of_sql]);
                        }
                        #$current_alias   = self::isRegisteredClass($name, $current_class)? sprintf("%s__%s", $result_of_sql, $current_table) : $current_table;
                        $current_alias   = self::isRegisteredClass($name, $current_class)? sprintf("%s__%s", /*$result_of_sql*/$result_of_incremented, $current_table) : $current_table;
                        $linked_table    = self::classToDbName($linked_class);
                        if ($link) {
                                /*$linked_alias    = self::isRegisteredClass($name, $linked_class)? sprintf("%s__%s", $link_sql, $linked_table) : $linked_table;*/

                                if (self::isRegisteredClass($name, $linked_class)) {
                                        $i = 0;
                                        if (isset($seen_results[$link_sql]))
                                                $i = $seen_results[$link_sql] + 1;

                                        $linked_alias = sprintf("%s__%s__%s", $link_sql, $i, $linked_table);
                                } else {
                                        /* TODO: why this split? */
                                        throw new Exception('why?');
                                        $linked_alias = $linked_table;
                                }
                        }
                        # also: $current_class
                        #       $linked_class
                        #       $link_stored_here
                        #       $nodes   = array of Node classes
                        #       $used    = array of classes requested for input or output (might be relationships)
                        #       $inputs  = array of classes requested for inputting (might be relationships)
                        #       $outputs = array of classes requested for outputting (might be relationships)
                        #       $non_key_inputs = array of classes that have non-keys specified as inputs
                        #
                        # from this point on it is strictly prohibited to use the $rule variable

                        # next line useful for debugging. do not remove.
                        #printf("last alias:       %s,\ncurrent alias:       %s,\nlinked alias:     %s,\nlink stored here: %d\ncurr unused node: %d\nlink unused node: %d\ni/pt as rel keys: %d\n\n", isset($last_alias)? $last_alias : '', $current_alias, isset($linked_alias)? $linked_alias : '', $link_stored_here, $curr_unused_node, $link_unused_node, $input_as_rel_keys);
                        $chain[] = $current_alias;

                        # SELECT
                        foreach ($destinations as $dest) {
                                if (isset($dest->output)) {
                                        if ($dest->class == $current_class) {
                                                $class = $current_class;
                                                $alias = $current_alias;
                                                # automagically grab parents of $current_class
                                                $class_reflection = new ReflectionClass($class);
                                                do {
                                                        # use reflection to SKIP abstract classes
                                                        if (!$class_reflection->isInstantiable())
                                                                continue;

                                                        if ($class != $current_class) { # parent classes
                                                                $last_alias_inherits = $alias;
                                                                $table = self::classToDbName($class);
                                                                $alias_name = self::classToDbName(self::RELATIONSHIP_INHERITS);
                                                                $alias = sprintf('%s__%s__%s', $current_alias, $alias_name, $table);

                                                                $from[] = sprintf("%s %s", $table, $alias);
                                                                foreach (self::getKeys($class) as $key)
                                                                        $where[] = sprintf("%s.%s__key__%s = %s.%s", $last_alias_inherits, $alias_name, $key, $alias, $key);
                                                        }
                                                        $properties = self::getProperties($class);
                                                        if (self::getKeys($class) == array(self::AUTO_PROPERTY_ID))
                                                                array_unshift($properties, self::AUTO_PROPERTY_ID);
                                                        foreach ($properties as $var)
                                                                $select[] = sprintf("%s.%s AS %s__%s", $alias, $var, $alias, $var);

                                                } while (($class_reflection = $class_reflection->getParentClass()) &&
                                                         ($class = $class_reflection->getName()) != self::ORM_CLASS_CLASS);

                                        } else if ($dest->class == $result_of) {
                                                $table = $link_stored_here? $current_alias : $last_alias;
                                                foreach (self::getProperties($result_of, true) as $var)
                                                        $select[] = sprintf("%s.%s__var__%s AS %s__%s", $table, $result_of_sql, $var, /*$result_of_sql*/$result_of_incremented, $var);
                                        }
                                }

                                if (isset($dest->input)) {
                                        $properties = self::getProperties($dest->class);
                                        if ($dest->class == $current_class) {
                                                if (self::getKeys($dest->class) == array(self::AUTO_PROPERTY_ID))
                                                        array_unshift($properties, self::AUTO_PROPERTY_ID);

                                                foreach ($dest->input as $key => $var) {
                                                        if (!in_array($key, $properties))
                                                                throw new OrmInputException('Property "%s" is not a member of Class "%s"', $key, $dest->class);

                                                        $key = self::propertyToDbName($key);
                                                        /*$var = self::sqlVar($name, $var);*/

                                                        if ($input_as_rel_keys)
                                                                $table_key = sprintf("%s.%s__key__%s", $last_alias, $result_of, $key);
                                                        else
                                                                $table_key = sprintf("%s.%s", $current_alias, $key);

                                                        $where[] = sprintf("%s = %s", $table_key, $var);
                                                }

                                        } else if ($dest->class == $result_of) {
                                                foreach ($dest->input as $key => $var) {
                                                        if (!in_array($key, $properties))
                                                                throw new OrmInputException('Property "%s" is not a member of Class "%s"', $key, $dest->class);

                                                        $key = self::propertyToDbName($key);
                                                        /*$var = self::sqlVar($name, $var);*/

                                                        if ($link_stored_here) {
                                                                $where[] = sprintf("%s.%s__var__%s = %s", $current_alias, $result_of_sql, $key, $var);
                                                        } else {
                                                                $where[] = sprintf("%s.%s__var__%s = %s", $linked_alias,  $result_of_sql, $key, $var);
                                                        }
                                                }
                                        }
                                }
                        }

                        if (!$curr_unused_node) {
                                # FROM
                                if (self::isRegisteredClass($name, $current_class))
                                        $from[] = sprintf("%s %s", $current_table, $current_alias);
                                else
                                        $from[] = $current_alias;
                        }


                        # JOIN
                        if ($link) {
                                if ($link_stored_here) {
                                        if (!$link_unused_node) {
                                                foreach (self::getKeys($linked_class) as $key)
                                                        $where[] = sprintf("%s.%s__key__%s = %s.%s", $current_alias, $link_sql, $key, $linked_alias, $key);
                                        }

                                } else {
                                        $var_prefix = $curr_unused_node? sprintf("%s.%s__", $last_alias, $last_link_sql) : sprintf("%s.", $current_alias);
                                        foreach (self::getKeys($current_class) as $key)
                                                $where[] = sprintf("%s%s = %s.%s__key__%s", $var_prefix, $key, $linked_alias, $link_sql, $key);
                                }
                        }

                        # above here do not use $rule variable
                        $last_alias = $current_alias;
                        if (isset($link_sql))
                                $last_link_sql = $link_sql;
                        $last_rule  = $rule;
                }

                # format the chain to a reasonable string
                #$chain = preg_replace('#(?<alias>[^ ]+)__(?<class>[^ ]+)#', '(\1) -> \2', implode(' -> ', $chain));
                $chain = preg_replace_callback('#([^ ]+)__([^ ]+)__([^ ]+)#', array('self', 'chainFormatCallback'), implode(' -> ', $chain));

                $o = new OrmSqlComponents($chain);
                $o->set('SELECT', implode(', ', $select));
                $o->set('FROM',   implode(', ', array_unique($from)));
                if (sizeof($where) > 0)
                        $o->set('WHERE', implode(' AND ', $where ));

                # useful for debugging generated SQL:
                #printf("\n\n%s\n\n", SSql::format($o));
                return $o;
        }

        private static final function chainFormatCallback($match)
        {
                if ($match[1] == self::ALIAS_ANCHOR)
                        return self::dbToClassName($match[3]);

                list(, $relationship, $i, $class) = $match;

                # Taken the possibly contraversial option of refering to arrays in the same way that C does; i.e. *a == a[0], except I also dropped the pointer dereference sugar... good/bad? you decide...
                if ($i == '0')
                        $i = '';
                else
                        $i = sprintf('[$i]', $i);

                return sprintf('(%s%s) -> %s', self::dbToClassName($relationship), $i, self::dbToClassName($class));
        }

        private static final function cmpDests($a, $b)
        {
                if (($cmp = strcmp($a['class'], $b['class'])) != 0)
                        return $cmp;

                if (isset($a['input']) && isset($b['input']) && ($cmp = strcmp(serialize(ksort($a['input'])), serialize(ksort($b['input'])))) != 0)
                        return $cmp;

                if (isset($a['output']) && isset($b['output']))
                        return ($a['output'] && $b['output']);

                return 0;

        }

        /*
        private static final function cmpWaypts($a, $b)
        {
                $a_str = is_string($a);
                $b_str = is_string($b);
                $a_obj = is_object($a);
                $b_obj = is_object($b);

                if ($a_str && $b_obj)
                        return 1;

                if ($a_obj && $b_str)
                        return -1;

                if ($a_str && $b_str)
                        return strcmp($a, $b);

                if ($a_obj && $b_obj) {
                        $a_input_set  = isset($a->input);
                        $a_output_set = isset($a->output);
                        $a_alias_set  = isset($a->alias);
                        $b_input_set  = isset($b->input);
                        $b_output_set = isset($b->output);
                        $b_alias_set  = isset($b->alias);

                        if ($a_input_set  && $b_input_set  && ($cmp = strcmp($a->input,  $b->input))  != 0)
                                return $cmp;
                        if ($a_output_set && $b_output_set && ($cmp = strcmp($a->output, $b->output)) != 0)
                                return $cmp;
                        if ($a_alias_set  && $b_alias_set  && ($cmp = strcmp($a->alias,  $b->alias))  != 0)
                                return $cmp;

                        if ($a_input_set  && !$b_input_set)
                                return 1;
                        if ($b_input_set  && !$a_input_set)
                                return -1;
                        if ($a_output_set && !$b_output_set)
                                return 1;
                        if ($b_output_set && !$a_output_set)
                                return -1;
                        if ($a_alias_set  && !$b_alias_set)
                                return 1;
                        if ($b_alias_set  && !$a_alias_set)
                                return -1;
                }

                return 0;
        }
        */

        /* Wrapper around cacheing functions to avoid routing taking place every time */
        public static final function getSql($destinations, $chain = NULL, $name = NULL)
        {
                # Copy and sort destinations
                $dests = $destinations;
                # only sort the tail, since the head is an anchor
                $head  = array_shift($dests);

                usort($dests,  array('self', 'cmpDests' ));

                array_unshift($dests, $head);

                $vals = array();
                $func_args = array();
                $hash = serialize($chain);
                foreach ($dests as $i => &$d) {
                        self::objectify($d);
                        if (isset($d->input))
                                foreach ($d->input as $key => &$val) {
                                        $varname = sprintf('$i%d_%s%s_%s', $i, self::classToDbName($d->class), isset($d->alias)? '_'.self::classToDbName($d->alias) : '', self::propertyToDbName($key));
                                        $func_args[] = $varname;
                                        $vals[] = self::sqlVar($name, $val);
                                        $val = sprintf('{%s}', $varname);
                                }

                        if (isset($d->output))
                                $hash .= sprintf('%s_%s', $d->class, isset($d->alias)? $d->alias : '');
                }

                $hash = 'h'.sha1($hash.($func_args = implode(', ', $func_args)));

                $func_name = class_exists(self::ORM_CLASS_SQL_CACHE)? call_user_func(array(self::ORM_CLASS_SQL_CACHE, 'getFuncName'), $hash, $name) : NULL;

                if ($func_name && method_exists(self::ORM_CLASS_SQL_CACHE, $func_name)) { # found in cache
                        $sql = call_user_func_array(array(self::ORM_CLASS_SQL_CACHE, $func_name), $vals);

                } else if ($func_name && $func_body = call_user_func(array(self::ORM_CLASS_SQL_CACHE, 'getRecentlyCachedBody'), $hash, $name)) { # found in temporary cache
                        $sql = call_user_func_array(create_function($func_args, $func_body), $vals);

                } else { # not found in any cache; generate
                        $func_body = sprintf("return \n%s;", self::generateSqlComponents($name, $dests, $chain)->toRecipe());

                        # add to cache
                        if (class_exists(self::ORM_CLASS_SQL_CACHE))
                                call_user_func(array(self::ORM_CLASS_SQL_CACHE, 'add'), $hash, $func_args, $func_body, $name);

                        $sql = call_user_func_array(create_function($func_args, $func_body), $vals);
                }

                return $sql;
        }

        # TODO: work out where to use self::resolveRelationship($name, $class_a, $class_b) -- it's a useful function and will be a nice guard

        /** Takes an SQL result and converts it into a chain of objects (returning an OrmChainResult) **/
        protected static final function objectsFromResult($name, $result, $chain)
        {
                $chain_result = new OrmChainResult(&$chain);

                foreach (SSql::getResultsFor($result) as $row) { # by row
                        $row_class_vars = array();
                        $row_rship_vars = array();

                        foreach ($row as $key => $val) {         # by col
                                switch (sizeof($parts = explode('__', $key))) {
                                        case 3: # result_of, iterable, property -- i.e. populate a relationship
                                                list($r_alias, $i, $r_property) = $parts;
                                                $r_alias        = self::dbToClassName($r_alias);
                                                $r_property     = self::dbToPropertyName($r_property);

                                                $key = sprintf('%s__%s', Orm::dbToClassName($r_alias), $i);
                                                if (!isset($row_rship_vars[$key]))
                                                        $row_rship_vars[$key] = array();

                                                $row_rship_vars[$key][$r_property] = $val;
                                                break;

                                        case 4: # result_of, iterable, class, property -- i.e. populate a class
                                                list($r_alias, $i, $r_class, $r_property) = $parts;
                                                $r_class    = self::dbToClassName($r_class);
                                                $r_alias    = self::dbToClassName($r_alias);
                                                $r_property = self::dbToPropertyName($r_property);

                                                $key = sprintf('%s__%s__%s', Orm::dbToClassName($r_alias), $i, Orm::dbToClassName($r_class));
                                                if (!isset($row_class_vars[$key]))
                                                        $row_class_vars[$key] = array();

                                                $row_class_vars[$key][$r_property] = $val;
                                                break;

                                        case 6: # child_result_of, iterable, child, "inherits", class, property -- i.e. populate the rest of a class
                                                list($r_child_alias, $i, $r_child, $inherits_e_inherits, $r_class, $r_property) = $parts;
                                                $r_class             = self::dbToClassName($r_class);
                                                $inherits_e_inherits = self::dbToClassName($inherits_e_inherits);
                                                $r_child             = self::dbToClassName($r_child);
                                                $r_child_alias       = self::dbToClassName($r_child_alias);
                                                $r_property          = self::dbToPropertyName($r_property);

                                                if ($inherits_e_inherits != self::RELATIONSHIP_INHERITS)
                                                        throw new OrmException('Not expecting key of form %s', $key);

                                                $key = sprintf('%s__%s__%s', Orm::dbToClassName($r_child_alias), $i, Orm::dbToClassName($r_child));
                                                if (!isset($row_class_vars[$key]))
                                                        $row_class_vars[$key] = array();

                                                if ($r_property == self::AUTO_PROPERTY_ID)
                                                        $row_class_vars[$key][sprintf('__%s__%s', $r_class, self::AUTO_PROPERTY_ID)] = $val;
                                                else
                                                        $row_class_vars[$key][$r_property] = $val;
                                                break;

                                        default:
                                                throw new OrmException('Unexpected key encountered: %s', print_r($parts, true));
                                }
                        }

                        $row_class_objs = array();
                        $row_rship_objs = array();

                        foreach ($row_class_vars as $alias__class => $vars) {
                                list($alias, $i, $class) = explode('__', $alias__class);
                                if (!self::isRegisteredClass($name, $class))
                                        throw new OrmException('Class %s is not registered for Schema %s', $class, $name);

                                $obj = new $class(OrmClass::CONSTR_CMD_POPULATE_SYNCED_ARRAY, $name, $vars);

                                $row_class_objs[sprintf('%s__%s', $alias, $i)] = $obj;
                        }
                        foreach ($row_rship_vars as $relationship => $vars) {
                                list($class, $i) = explode('__', $relationship);
                                if (!self::isRegisteredIRelationship($name, $class))
                                        throw new OrmException('Relationship %s is not registered or is not instanciable for Schema %s', $class, $name);

                                $obj = new $class($vars, $name);
                                /*$obj->setLoadedFromDb();*/
                                # TODO: WORKING_ON -- this should set each class it's loading

                                $row_rship_objs[$relationship] = $obj;
                        }
                        $chain_result->add(new OrmChainRow(&$chain, $row_class_objs, $row_rship_objs));
                }

                return $chain_result->finalize();
        }

        protected static function objectsFromDestinations($destinations, $chain = NULL, $name = NULL)
        {
                $sql      = self::getSql($destinations, $chain, $name);
                $result   = self::getSqlResult($name, $sql);

                return    self::objectsFromResult($name, $result, $sql->getChain());
        }

        public static function load($class, $name = NULL)
        {
                $destinations = array(array('class' => $class, 'output' => true));
                return self::objectsFromDestinations($destinations, NULL, $name)->anchored();
        }

        public function __destruct()
        {
                #$this->save();
        }

        abstract public function equals(Orm $that);
        abstract public function save();
}

abstract class OrmClass extends Orm
{
        private $setup_name = NULL;
        private $ids        = array();
        /*private $synced     = array();*/

        ### helper functions for array callbacks ###
        private final function sqlKey($key)
        {
                return SSql::escape(self::propertyToDbName($key), self::getSSqlName($this->setup_name));
        }

        private final function sqlValue($key)
        {
                return self::sqlVar($this->setup_name, $this->$key);
        }

        private final function sqlKeyValue($key)
        {
                return sprintf('%s = %s', $this->sqlKey($key), $this->sqlValue($key));
        }
        ### helper functions for array callbacks ###

        /** Insert a row into the DB **/
        private function insert($class)
        {
                $insert = array();
                if (Orm::$use_guids) {
                        # TODO
                        # generate guid
                }

                # TODO: parental relationships??????

                $properties = self::getProperties($class);
                SSql::query(sprintf("INSERT INTO %s (%s) VALUES (%s)",
                                    self::classToDbName($class),
                                    implode(', ', array_map(array($this, 'sqlKey'),   $properties)),
                                    implode(', ', array_map(array($this, 'sqlValue'), $properties))),
                            self::getSSqlName($this->setup_name)
                );
                # WORKING_ON

                if (!Orm::$use_guids) {
                        # TODO
                        # get id from db
                }
        }

        /** Can be constructed with the following syntaxes:
                new OrmClass()
                new OrmClass($array)
                new OrmClass($array, $name)
                new OrmClass($key1,  $key2)
                new OrmClass($key1,  $key2, $name) **/

        const CONSTR_CMD_POPULATE_SYNCED_ARRAY = '___CONSTR_CMD_POPULATE_SYNCED_ARRAY';
        const CONSTR_CMD_POP_FROM_ARR          = '___CONSTR_CMD_POP_FROM_ARR';
        const CONSTR_CMD_POP_FROM_OBJ          = '___CONSTR_CMD_POP_FROM_OBJ';
        const CONSTR_CMD_POP_FROM_DB           = '___CONSTR_CMD_POP_FROM_DB';
        const CONSTR_CMD_NEW                   = '___CONSTR_CMD_NEW';


        /*
                populate from db (concerns delegated to other process), do not insert
                populate from array and insert
                populate from other object (must be typeof this object, will do it with reflection and will note synced classes, will also populate from arr (2nd arg)) and insert
                populate from db by keys
                don't populate from anywhere, just create a new empty row (will only work for auto id)
        */
        public function __construct($command = NULL, $name = NULL)
        {
                $args = func_get_args();

                switch ($command) {
                        case self::CONSTR_CMD_POPULATE_SYNCED_ARRAY:
                        case self::CONSTR_CMD_POP_FROM_ARR:
                        case self::CONSTR_CMD_POP_FROM_OBJ:
                        case self::CONSTR_CMD_POP_FROM_DB:
                        case self::CONSTR_CMD_NEW:
                                $this->setup_name = $name;

                                # shift off known args
                                array_shift($args);
                                array_shift($args);
                                break;
                }

                switch ($command) {
                        case self::CONSTR_CMD_POP_FROM_ARR:
                                $this->applyArray($args[0]);
                                break;
                        case self::CONSTR_CMD_POP_FROM_OBJ:
                                break;
                        case self::CONSTR_CMD_POP_FROM_DB:
                                break;
                        case self::CONSTR_CMD_POPULATE_SYNCED_ARRAY:
                                $this->applyArray($args[0]);
                                $this->setAllLoaded();
                                break;
                        default:
                                throw new OrmInputException('Guessing not supported, please explicitly specify construction type');
                                self::constructGuess($args);
                }

                $this->ensureObjectInDb();
        }

        /** Iterate over classes for this object and set them all to "loaded" **/
        private function setAllLoaded()
        {
                $class_reflection = new ReflectionClass(get_class($this));
                do {
                        if ($class_reflection->isInstantiable())
                                $this->setLoadedFromDb($class_reflection->getName());

                } while (($class_reflection = $class_reflection->getParentClass()) && # get the parent
                          $class_reflection->getName() != __CLASS__);                 # check that we're not hitting the top
        }

        /** Load given array values into this object **/
        private function applyArray($arr)
        {
                foreach ($this as $key => $value)
                        if (isset($arr[$key]))
                                $this->$key = $arr[$key];
        }

        /** Load given object values into this object, duplicating loaded status, too **/
        private function applyObject(Orm $o)
        {
                if (!($this instanceof $o))
                        throw new OrmInputException('Passed object (of type %s) is not a parent of this class (%s)', get_class($o), get_class($this));

                # use reflection, iterate over classes, apply values to this object, apply synced marker according to $o's synced markers
                $class_reflection = new ReflectionClass(get_class($o));
                do {
                        foreach ($class_reflection->getProperties() as $property_reflection) {
                                $p_name = $property_reflection->name;
                                $this->$p_name = $o->$p_name;
                        }

                        if ($class_reflection->isInstantiable())
                                $this->setLoadedFromDb($o->getLoadedFromDb($class_reflection->getName()));

                } while (($class_reflection = $class_reflection->getParentClass()) && # get the parent
                          $class_reflection->getName() != __CLASS__);                 # check that we're not hitting the top
        }

        private function constructGuess($args)
        {
                # use this to work out whether an arg refers to an Orm name
                $name_specified = 0;

                $next_step = NULL;

                if (func_num_args() == 0) { # new blank object => create in DB
                        $next_step = 'create';

                } elseif (is_array($input = func_get_arg(0))) { # array = new object with initial values => create in DB
                        if (func_num_args() > 1)
                                $name_specified = 1;

                        foreach($input as $key => $val)
                                $this->$key = $val;

                        $next_step = 'create';

                } else { # keys passed => load from DB
                        $keys = self::getKeys(get_class($this));
                        $name_specified = sizeof($keys);

                        if (func_num_args() < (sizeof($keys) - 1))
                                throw new OrmInputException('Not enough keys were passed in');

                        # TODO: no need to do this here; the load() will overwrite them anyway
                        $i = 0;
                        foreach($keys as $key)
                                $this->$key = func_get_arg($i++);

                        $next_step = 'load';
                }

                if ($name_specified > 0) {
                        $this->setup_name = func_get_arg($name_specified);
                }

                # TODO: refactor this crap
                switch ($next_step) {
                        case 'create':
                                $this->ensureObjectInDb();
                                break;

                        case 'load':
                                $this->loadObjectFromDb();
                                break;

                        default:
                                throw new OrmException('Unknown step: %s', self::debugDump($next_step));
                }
        }

        /** Ensure classes exist in the database, skipping already synced classes **/
        private final function ensureObjectInDb()
        {
                # WORKING_ON
                $class_reflection = new ReflectionClass(get_class($this));
                do {
                        # use reflection to SKIP abstract classes
                        if (!$class_reflection->isInstantiable())
                                continue;

                        if (!$this->getLoadedFromDb($class_reflection->getName())) {
                                $this->insert($class_reflection->getName());
                                break;
                        }

                } while (($class_reflection = $class_reflection->getParentClass()) && # get the parent
                          $class_reflection->getName() != __CLASS__);                 # check that we're not hitting the top
        }

        private final function loadObjectFromDb()
        {
                # TODO -- nice and easy, just load based on keys
                # TODO: would be far preferable to use the objectsFromDestinations() code, possibly by refactoring into another shareable function. this is needed because otherwise we're in object A and OrmClass->load() is generating object B, instead of populating object A
                # TODO:NOTE01
                # TODO: must use $this->setLoadedFromDb() per class
                throw new Exception('TODO');
        }

        ### Accessors ###
        public final function getId($class = NULL)
        {
                if ($class && !isset($this->ids[$class]))
                        throw new OrmInputException('Supply a valid class');

                return $class? $this->ids[$class] : $this->ids[get_class($this)];
        }

        public final function getKeyValues($class)
        {
                if (!($this instanceof $class))
                        throw new OrmInputException('Must supply a class that makes up an object of this type -- '.get_class($this));

                $array = array();
                foreach (self::getKeys($class) as $key)
                        $array[$key] = $this->$key;

                return $array;
        }
        ### Accessors ###

        public function equals(Orm $that)
        {
                if (($this_class = get_class($this)) != ($that_class = get_class($that)))
                        return FALSE;

                if ($this->getKeyValues($this_class) !== $that->getKeyValues($that_class))
                        return FALSE;

                return TRUE;
        }

        public function save()
        {
                return FALSE;
                # TODO
                # do this in steps of added functionality:
                # 1. save properties of self
                # remember about supporting options for cacading deletes/updates; this stuff should be disableable via Orm::setup()


                # order of saving relations depends entirely upon WHERE the relation is stored; if it's stored HERE we can't save until THAT relation is saved

                $class_reflection = new ReflectionClass($class);
                do {
                        if (!$class_reflection->isInstantiable()) # we're only interested in concrete classes
                                continue;

                        $class = $class_reflection->getName();
                        $properties = self::getProperties($class);

                        # TODO: UPDATE SQL here

                } while (($class_reflection = $class_reflection->getParentClass()) && # get the parent
                          $class_reflection->getName() != self::ORM_CLASS_CLASS);     # check that we're not hitting the top
        }

        public function delete()
        {
                # TODO
                # remember about supporting options for cacading deletes/updates; this stuff should be disableable via Orm::setup()
        }

        /** Request objects by class type to have them returned from the database (classes passed as varargs) **/
        public final function getRelsByClasses() {
                $chain = NULL;
                $classes = func_get_args();

                foreach ($classes as $i => $class) {
                        if (strstr($class, ' -> ')) {
                                if (!is_null($chain))
                                        throw new OrmInputException('Multiple chains have been passed. Already registered "%s" and now encountered "%s"', $chain, $class);

                                $chain = $class;
                                array_splice($classes, $i, 1); # remove the chain item from the classes array
                        }
                }

                $destinations = array();
                # add this class first with input set, and no output
                $destinations[] = array('class' => $class = get_class($this),
                                        'input' => $this->getKeyValues($class));

                # add the other classes as outputs
                foreach ($classes as $class)
                        $destinations[] = array('class' => $class, 'output' => true);

                return self::objectsFromDestinations($destinations, $chain, $this->setup_name);
        }

        /** Request objects by single class type to have them returned from the database **/
        public final function getRelsByClass($class, $chain = NULL) {

                $destinations = array();
                # add this class first with input set, and no output
                $destinations[] = array('class' => $thisclass = get_class($this),
                                        'input' => $this->getKeyValues($thisclass));

                # add the other class as an output
                $destinations[] = array('class' => $class, 'output' => true);

                return self::objectsFromDestinations($destinations, $chain, $this->setup_name)->byClass($class);
        }
}

abstract class OrmRelationship extends Orm
{
        private $setup_name = NULL;

        # TODO
        # it would be appropriate to pass in the objects (what about ternary etc.?) at __construct() time

        # Can be constructed with the following syntaxes:
        #       new OrmClass()
        #       new OrmClass($array)
        #       new OrmClass($array, $name)
        public function __construct()
        {
                # no args = no further setup
                if (func_num_args() == 0) return;

                # use this to work out whether an arg refers to an Orm name
                $name_specified = 0;

                if (is_array($input = func_get_arg(0))) { # array = new object with initial values
                        if (func_num_args() == 2)
                                $name_specified = 1;

                        foreach($input as $key => $val)
                                $this->$key = $val;

                }

                if ($name_specified > 0) {
                        $this->setup_name = func_get_arg($name_specified);
                }
        }

        public function equals(Orm $that)
        {
                # TODO: this is a stub
                return FALSE;
        }

        public function save()
        {
                # TODO: this is a stub
                return FALSE;
        }
}

class OrmDbCreation
{
        public static function getDbObjects($name = null)
        {
                $objs = array();
                $o = (object) array('is_class'         => false,
                                    'is_irelationship' => false,
                                    'keys'             => array(),
                                    'properties'       => array(),
                                    'parent'           => NULL,
                                    'relationships'    => array());

                foreach (Orm::getRules($name) as $rule) {
                        foreach ($rule as $name => $class) {
                                if (!is_string($class))
                                        continue;

                                if (!class_exists($class)) {
                                        if (!isset($objs[$class]))
                                                $objs[$class] = clone $o;

                                        continue;
                                }

                                $class_reflection = new ReflectionClass($class);
                                do {
                                        if (!$class_reflection->isInstantiable())
                                                continue;

                                        if (!isset($objs[$class_reflection->getName()]))
                                                $objs[$class_reflection->getName()] = clone $o;


                                } while (($class_reflection = $class_reflection->getParentClass())   && # get the parent
                                          $class_reflection->getName() != Orm::ORM_CLASS_CLASS       && # check that we're not hitting the top
                                          $class_reflection->getName() != Orm::ORM_CLASS_RELATIONSHIP); # check that we're not hitting the top
                        }

                        $objs[$rule->input]->relationships[$rule->relationship] = $rule->output;
                }

                foreach ($objs as $class => $o) {
                        if ($o->is_class = (class_exists($class) && is_subclass_of($class, Orm::ORM_CLASS_CLASS))) {
                                $o->keys       = Orm::getKeys($class);
                                $o->properties = Orm::getProperties($class);

                                $class_reflection = new ReflectionClass($class);
                                while (($class_reflection = $class_reflection->getParentClass()) && $class_reflection->getName() != Orm::ORM_CLASS_CLASS && $class_reflection->getName() != Orm::ORM_CLASS_RELATIONSHIP) {
                                        if (!$class_reflection->isInstantiable())
                                                continue;

                                        $o->parent = $class_reflection->getName();
                                        break;
                                }
                        }

                        if ($o->is_irelationship = (class_exists($class) && is_subclass_of($class, Orm::ORM_CLASS_RELATIONSHIP)))
                                $o->properties = Orm::getProperties($class, true);

                        if ($o->keys == array(Orm::AUTO_PROPERTY_ID))
                                array_unshift($o->properties, Orm::AUTO_PROPERTY_ID);
                }

                return $objs;
        }
}

class OrmSqlComponents
{
        private $chain = '';

        private $items = array('SELECT'   => '',
                               'INSERT'   => '',
                               'UPDATE'   => '',
                               'DELETE'   => '',

                               'FROM'     => '',

                               'WHERE'    => '',

                               'GROUP BY' => '',

                               'HAVING'   => '',

                               'ORDER BY' => '',

                               'LIMIT'    => '');

        public function get($item)
        {
                if (!isset($this->items[$item]))
                        throw new OrmInputException('Key %s does not exist', $item);

                return $item;
        }

        public function set($item, $val)
        {
                if (!isset($this->items[$item]))
                        throw new OrmInputException('Key %s does not exist', $item);

                if (!is_string($val))
                        throw new OrmInputException('Value must be a string. Type %s passed.', gettype($val));

                $this->items[$item] = $val;
        }

        # Simple function to convert SQL components into an SQL statement
        public function __toString()
        {
                $sql = array();
                foreach ($this->items as $command => $args)
                        if (!empty($args))
                                $sql[] = sprintf("%s %s", $command, $args);

                return implode(' ', $sql);
        }

        public function __construct($chain = '', $items = array())
        {
                if (!is_string($chain))
                        throw new OrmInputException('Chain must be a string. Type %s passed.', gettype($chain));

                $this->chain = $chain;
                $this->items = array_merge($this->items, $items);
        }

        public function toRecipe()
        {
                $str = sprintf('new %s(', __CLASS__);
                $pad = str_pad('', strlen($str));
                $str .= sprintf("'%s',\n%sarray(", $this->chain, $pad);
                $pad = str_pad($pad, strlen($pad) + strlen('array('));

                $arr = array();
                foreach ($this->items as $key => $value) {
                        $arrow_pad = str_pad('', 8-strlen($key));
                        $arr[] = sprintf('\'%s\'%s => "%s"', $key, $arrow_pad, $value);
                }

                $str .= sprintf('%s))', implode(",\n$pad", $arr));

                return $str;
        }

        public function getChain()
        {
                return $this->chain;
        }
}

class OrmChainRow
{
        private $chain = '';
        private $class = array();
        private $rship = array();

        public function __construct($chain, array $class, array $rship)
        {
                $this->chain = $chain;
                $this->class = $class;
                $this->rship = $rship;
        }

        public function _rshipByAlias($alias, $i = 0)
        {
                $key = sprintf('%s__%s', $alias, $i);
                if (!isset($this->rship[$key]))
                        throw new OrmInputException('No alias with the name %s exists', $key);

                return $this->rship[$key];
        }

        public function _classByAlias($alias, $i = 0)
        {
                $key = sprintf('%s__%s', $alias, $i);
                if (!isset($this->class[$key]))
                        throw new OrmInputException('No alias with the name %s exists', $key);

                return $this->class[$key];
        }

        public function __get($alias)
        {
                return $this->_rshipByAlias($alias);
        }

        public function __call($alias, $args)
        {
                return $this->_classByAlias($alias);
        }

        public function __set($key, $value)
        {
                throw new OrmException('Objects of class %S are immutable', __CLASS__);
        }

        public function __isset($alias)
        {
                return isset($this->rship[$alias]);
        }

        /** A helper to get the anchored OrmClass object **/
        public function _anchored()
        {
                return $this->_classByAlias(Orm::ALIAS_ANCHOR);
        }


        /** A helper to return an item by class, so long as the class is unique in the chain **/
        public function _byClass($class)
        {
                $item = NULL;

                foreach ($this->class as &$t)
                        if (get_class($t) == $class)
                                if ($item)
                                        throw new OrmInputException('The class %s is not unique in the chain, try using chain aliases', $class);
                                else
                                        $item = $t;

                foreach ($this->rship as &$t)
                        if (get_class($t) == $class)
                                if ($item)
                                        throw new OrmInputException('The class %s is not unique in the chain, try using chain aliases', $class);
                                else
                                        $item = $t;

                return $item;
        }
}

class OrmChainResult implements Iterator
{
        private $chain = '';
        private $final = FALSE;
        private $rows  = array();

        public function __construct($chain)
        {
                $this->chain = $chain;
        }

        public function add(OrmChainRow $o)
        {
                if ($this->final)
                        throw new OrmException('Cannot add to a finalized %s', __CLASS__);

                $this->rows[] = $o;
                return $this;
        }

        /** Make the object immutable **/
        public function finalize()
        {
                $this->final = TRUE;
                return $this;
        }

        /** Return all OrmRelationship objects for given alias **/
        public function rshipByAlias($alias)
        {
                $arr = array();
                foreach ($this->rows as $r)
                        $arr[] = $r->$alias;

                return $arr;
        }

        /** Return all OrmClass objects for given alias **/
        public function classByAlias($alias)
        {
                $arr = array();
                foreach ($this->rows as $r)
                        $arr[] = $r->$alias();

                return $arr;
        }

        public function __set($key, $value)
        {
                throw new OrmException('Objects of class %S are immutable', __CLASS__);
        }

        # TODO: consider using this in combination with __get($cmd) to check whether a given class is possible to retrieve
        public function __isset($alias)
        {
                return current($this->rows)->__isset($alias);
        }

        /*
        public function __get($alias)
        {
                return $this->_classByAlias($alias);
        }

        public function __call($alias, $args)
        {
                return $this->_classByAlias($alias);
        }
        */

        /** Wrapper to simplify use; can request objects of a given type this way, so long as they are unique in the chain **/
        public function __get($cmd)
        {
                $class_cmd = 'class';

                if     ($cmd == 'anchored')
                        return $this->anchored();
                elseif (strpos($cmd, $class_cmd) === 0)
                        return $this->byClass(substr($cmd, strlen($class_cmd)));
        }

        /** A helper to get the anchored OrmClass objects **/
        public function anchored()
        {
                return $this->classByAlias(Orm::ALIAS_ANCHOR);
        }

        /** A helper to return items by class, so long as the class is unique in the chain **/
        public function byClass($class)
        {
                $arr = array();
                foreach ($this->rows as $r)
                        $arr[] = $r->_byClass($class);

                return $arr;
        }

        #Iteration
        public function rewind()
        {
                reset($this->rows);
        }

        public function current()
        {
                return current($this->rows);
        }

        public function key()
        {
                return key($this->rows);
        }

        public function next()
        {
                return next($this->rows);
        }

        public function valid()
        {
                return $this->current() !== FALSE;
        }
        #/Iteration
}

?>
