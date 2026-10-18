<?php
require 'vendor/autoload.php';
use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;

// MySQL shim reproducing the medias case: table with TWO FKs, dropping ONE FK-bearing column
$log = [];
$pdo = new class($log) extends \Pdo\Sqlite {
    public function __construct(public array &$log) {
        parent::__construct('sqlite::memory:');
        $this->createFunction('DATABASE', fn (): string => 'test', 0);
        $this->exec("ATTACH DATABASE ':memory:' AS information_schema");
        foreach ([
            'tables (table_schema TEXT, table_name TEXT)',
            'columns (table_schema TEXT, table_name TEXT, ordinal_position INTEGER, column_name TEXT, column_type TEXT, is_nullable TEXT, column_default TEXT, column_key TEXT)',
            'statistics (table_schema TEXT, table_name TEXT, index_name TEXT, column_name TEXT, non_unique INTEGER, seq_in_index INTEGER)',
            'key_column_usage (constraint_schema TEXT, constraint_name TEXT, table_schema TEXT, table_name TEXT, column_name TEXT, referenced_table_name TEXT, referenced_column_name TEXT, ordinal_position INTEGER)',
            'referential_constraints (constraint_schema TEXT, constraint_name TEXT, delete_rule TEXT, update_rule TEXT)',
        ] as $ddl) $this->exec("CREATE TABLE information_schema.$ddl");
    }
    public function prepare(string $q, array $o = []): PDOStatement|false {
        $this->log[] = $q;
        if (preg_match('/DROP\s+FOREIGN\s+KEY\s+[`"]?([A-Za-z0-9_]+)/i', $q, $m) === 1) {
            $n = $m[1];
            $this->exec("DELETE FROM information_schema.key_column_usage WHERE constraint_name = '$n'");
            $this->exec("DELETE FROM information_schema.referential_constraints WHERE constraint_name = '$n'");
            return new Noop();
        }
        return parent::prepare($q, $o);
    }
    public function exec(string $q): int|false { $this->log[] = $q; return parent::exec($q); }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false { $this->log[] = $q; return parent::query($q, $m, ...$a); }
};
class Noop extends PDOStatement {
    public function execute(?array $p = null): bool { return true; }
}

$mysql = new MySqlConnection($pdo);
$mysql->statement('CREATE TABLE x (id INTEGER PRIMARY KEY)');
$mysql->statement('CREATE TABLE medias (id INTEGER PRIMARY KEY, circle_id INTEGER, uploader_id INTEGER)');

// catalog: medias has 3 cols, 2 FKs (medias_ibfk_1 on circle_id, medias_ibfk_2 on uploader_id)
$pdo->exec("INSERT INTO information_schema.tables VALUES ('test','x')");
$pdo->exec("INSERT INTO information_schema.tables VALUES ('test','medias')");
$i = 1;
foreach ([['id','int','NO',null,'PRI'],['circle_id','bigint','YES',null,''],['uploader_id','bigint','YES',null,'']] as $c) {
    $pdo->exec("INSERT INTO information_schema.columns VALUES ('test','medias',$i,'{$c[0]}','{$c[1]}','{$c[2]}'," . var_export($c[3], true) . ",'{$c[4]}')");
    $i++;
}
$pdo->exec("INSERT INTO information_schema.key_column_usage VALUES ('test','medias_ibfk_1','test','medias','circle_id','x','id',1)");
$pdo->exec("INSERT INTO information_schema.referential_constraints VALUES ('test','medias_ibfk_1','NO ACTION','NO ACTION')");
$pdo->exec("INSERT INTO information_schema.key_column_usage VALUES ('test','medias_ibfk_2','test','medias','uploader_id','x','id',1)");
$pdo->exec("INSERT INTO information_schema.referential_constraints VALUES ('test','medias_ibfk_2','NO ACTION','NO ACTION')");

$users = (new Blueprint('x'))->id()->column(ColumnType::String, 'n', length: 50);
$desired = (new Blueprint('medias'))
    ->id()
    ->column(ColumnType::BigInt, 'uploader_id', foreign: 'x.id');

echo "=== diff ===\n";
$differ = new SchemaDiffer($mysql->schemaInspector);
$changes = $differ->diff([$users, $desired]);
foreach ($changes as $k => $c) {
    echo "$k: {$c->operation->value} subject=" . json_encode($c->subject)
        . ' drops=' . json_encode($c->blueprint->getDropForeignKeys())
        . ' | ' . substr($c->description, 0, 70) . "\n";
}

echo "\n=== applying in order ===\n";
foreach ($changes as $k => $c) {
    try {
        $mysql->apply($c);
        echo "$k: OK ({$c->operation->value})\n";
    } catch (Throwable $e) {
        echo "$k: FAIL ({$c->operation->value}): " . $e->getMessage() . "\n";
        // continue to see what WOULD run
    }
    echo "  ALTER stmts so far: " . count(array_filter($log, fn($s) => str_starts_with($s, 'ALTER TABLE'))) . "\n";
    foreach (array_filter($log, fn($s) => str_contains($s, 'DROP FOREIGN KEY')) as $s) echo "  FKDROP: $s\n";
}
