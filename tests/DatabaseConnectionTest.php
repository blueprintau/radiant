<?php

namespace BlueprintAU\Radiant\Tests;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Drivers\MySql\MySqlDriver;
use PHPUnit\Framework\TestCase;

class DatabaseConnectionTest extends TestCase
{

    static array $config;

    public static function setUpBeforeClass(): void
    {
        self::$config = require __DIR__.'/../database-config.php';
    }

    public function test_adding_a_new_driver() : void
    {
        Database::addDriver('mysql', MySqlDriver::class);
        $this->assertTrue(Database::hasDriver('mysql'));
    }

    public function test_adding_a_new_connection() : void
    {
        Database::addDriver('mysql', MySqlDriver::class);

        $this->assertTrue(Database::hasDriver('mysql'));

        try{
        Database::addConnection('mysql', self::$config);
        }catch(\Exception $exception){
            $this->fail($exception->getMessage());
        }

    }

}