<?php

namespace Tests;

use Casbin\Enforcer;
use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use CasbinAdapter\Database\Adapter as DatabaseAdapter;
use PHPUnit\Framework\TestCase;
use TechOne\Database\Manager;
use Casbin\Persist\Adapters\Filter;

class AdapterTest extends TestCase
{
    protected $config = [];

    protected function initConfig()
    {
        $this->config = [
            'type' => 'mysql', // mysql,pgsql,sqlite,sqlsrv
            'hostname' => $this->env('DB_PORT', '127.0.0.1'),
            'database' => $this->env('DB_DATABASE', 'casbin'),
            'username' => $this->env('DB_USERNAME', 'root'),
            'password' => $this->env('DB_PASSWORD', ''),
            'hostport' => $this->env('DB_PORT', 3306),
        ];
    }

    protected function initDb()
    {
        $tableName = 'casbin_rule';
        $conn = (new Manager($this->config))->getConnection();
        $conn->execute('DELETE FROM '.$tableName);
        $conn->execute('INSERT INTO '.$tableName.' (ptype, v0, v1, v2) VALUES ( \'p\', \'alice\', \'data1\', \'read\') ');
        $conn->execute('INSERT INTO '.$tableName.' (ptype, v0, v1, v2) VALUES ( \'p\', \'bob\', \'data2\', \'write\') ');
        $conn->execute('INSERT INTO '.$tableName.' (ptype, v0, v1, v2) VALUES ( \'p\', \'data2_admin\', \'data2\', \'read\') ');
        $conn->execute('INSERT INTO '.$tableName.' (ptype, v0, v1, v2) VALUES ( \'p\', \'data2_admin\', \'data2\', \'write\') ');
        $conn->execute('INSERT INTO '.$tableName.' (ptype, v0, v1) VALUES ( \'g\', \'alice\', \'data2_admin\') ');
    }

    protected function getEnforcer()
    {
        $this->initConfig();
        $adapter = DatabaseAdapter::newAdapter($this->config);
        $this->initDb();

        return new Enforcer(__DIR__.'/rbac_model.conf', $adapter);
    }

    protected function getEnforcerWithAdapter(Adapter $adapter): Enforcer
    {
        $this->adapter = $adapter;
        $this->initDb($this->adapter);
        $model = Model::newModelFromString(
            <<<'EOT'
[request_definition]
r = sub, obj, act

[policy_definition]
p = sub, obj, act

[role_definition]
g = _, _

[policy_effect]
e = some(where (p.eft == allow))

[matchers]
m = g(r.sub, p.sub) && r.obj == p.obj && r.act == p.act
EOT
        );
        return new Enforcer($model, $this->adapter);
    }

    public function testLoadPolicy()
    {
        $e = $this->getEnforcer();
        $this->assertTrue($e->enforce('alice', 'data1', 'read'));
        $this->assertFalse($e->enforce('bob', 'data1', 'read'));
        $this->assertTrue($e->enforce('bob', 'data2', 'write'));
        $this->assertTrue($e->enforce('alice', 'data2', 'read'));
        $this->assertTrue($e->enforce('alice', 'data2', 'write'));
    }

    public function testLoadFilteredPolicy()
    {
        $this->initConfig();
        $adapter = DatabaseAdapter::newAdapter($this->config);
        $adapter->setFiltered(true);
        $e = $this->getEnforcerWithAdapter($adapter);
        $this->assertEquals([], $e->getPolicy());

        // string
        $filter = "v0 = 'bob'";
        $e->loadFilteredPolicy($filter);
        $this->assertEquals([
            //
            ['bob', 'data2', 'write', '', '', '']
        ], $e->getPolicy());

        // Filter
        $filter = new Filter(['', '', 'read']);
        $e->loadFilteredPolicy($filter);
        $this->assertEquals([
            ['alice', 'data1', 'read', '', '', ''],
            ['data2_admin', 'data2', 'read', '', '', ''],
        ], $e->getPolicy());

        // Closure
        $e->loadFilteredPolicy(function ($connection, $sql, &$rows) {
            $rows = $connection->query($sql . "v0 = 'alice'");
        });

        $this->assertEquals([
            ['alice', 'data1', 'read', '', '', ''],
        ], $e->getPolicy());
    }

    public function testAddPolicy()
    {
        $e = $this->getEnforcer();
        $this->assertFalse($e->enforce('eve', 'data3', 'read'));

        $e->addPermissionForUser('eve', 'data3', 'read');
        $this->assertTrue($e->enforce('eve', 'data3', 'read'));
    }

    public function testSavePolicy()
    {
        $e = $this->getEnforcer();
        $this->assertFalse($e->enforce('alice', 'data4', 'read'));

        $model = $e->getModel();
        $model->clearPolicy();
        $model->addPolicy('p', 'p', ['alice', 'data4', 'read']);

        $adapter = $e->getAdapter();
        $adapter->savePolicy($model);
        $this->assertTrue($e->enforce('alice', 'data4', 'read'));
    }

    public function testRemovePolicy()
    {
        $e = $this->getEnforcer();
        $this->assertFalse($e->enforce('alice', 'data5', 'read'));
        $e->addPermissionForUser('alice', 'data5', 'read');
        $this->assertTrue($e->enforce('alice', 'data5', 'read'));
        $e->deletePermissionForUser('alice', 'data5', 'read');
        $this->assertFalse($e->enforce('alice', 'data5', 'read'));
    }

    public function testRemoveFilteredPolicy()
    {
        $e = $this->getEnforcer();
        $this->assertTrue($e->enforce('alice', 'data1', 'read'));
        $e->removeFilteredPolicy(1, 'data1');
        $this->assertFalse($e->enforce('alice', 'data1', 'read'));

        $this->assertTrue($e->enforce('bob', 'data2', 'write'));
        $this->assertTrue($e->enforce('alice', 'data2', 'read'));
        $this->assertTrue($e->enforce('alice', 'data2', 'write'));

        $e->removeFilteredPolicy(1, 'data2', 'read');

        $this->assertTrue($e->enforce('bob', 'data2', 'write'));
        $this->assertFalse($e->enforce('alice', 'data2', 'read'));
        $this->assertTrue($e->enforce('alice', 'data2', 'write'));

        $e->removeFilteredPolicy(2, 'write');

        $this->assertFalse($e->enforce('bob', 'data2', 'write'));
        $this->assertFalse($e->enforce('alice', 'data2', 'write'));
    }

    protected function env($key, $default = null)
    {
        $value = getenv($key);
        if (is_null($default)) {
            return $value;
        }

        return false === $value ? $default : $value;
    }
}
