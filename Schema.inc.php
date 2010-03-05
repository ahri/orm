<?php

class Person extends OrmClass
{
        const Person_keys = 'dna_seq,surname,given_name';

        public $dna_seq;
        public $surname;
        public $given_name;

        public $dob;
}

class Employer extends OrmClass
{
        const Employer_keys = 'name';

        public $name;
}

abstract class Recorded extends OrmClass
{
        public $created;
        public $altered;
}

class Building extends Recorded
{
        public $address;
}

class Office extends Building
{
}

class Home extends Building
{
}

class LivesIn extends OrmRelationship
{
        public $since;
}

Orm::setup(<<<EOF
Person   to Person   as Partner
Person   to Employer as EmployedBy
Job      to Employer as Employs
Job      to Person   as EmployeeOf
Employer to Office   as Owns
Person   to Home     as LivesIn
EOF
);

?>
