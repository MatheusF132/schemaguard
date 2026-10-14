<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use SchemaGuard\Model\{Cardinality, Column, Id, Level, RefAction, Relationship, Rule, Table};
use SchemaGuard\Model\Project;
use SchemaGuard\Storage\ProjectStorage;

$p = new Project(name: 'Demo hospital', mode: Level::Beginner);

$patients = $p->addTable(Table::create('patients'));
$patientId = $patients->addColumn(Column::create('id', 'integer', ['primaryKey' => true, 'autoIncrement' => true, 'nullable' => false]));
$patients->addColumn(Column::create('name', 'varchar', ['length' => 120, 'nullable' => false, 'sensitive' => true]));

$appts = $p->addTable(Table::create('appointments'));
$apptId = $appts->addColumn(Column::create('id', 'integer', ['primaryKey' => true, 'autoIncrement' => true, 'nullable' => false]));
$apptPatient = $appts->addColumn(Column::create('patient_id', 'integer', ['nullable' => false]));

$p->relationships[] = new Relationship(
    Id::generate('ref'), $appts->id, [$apptPatient->id], $patients->id, [$patientId->id],
    Cardinality::ManyToOne, RefAction::Restrict,
);
$p->rules[] = new Rule(
    'FK_WITHOUT_INDEX', Level::Advanced,
    'Esta chave estrangeira nao tem indice.',
    'Consultas que juntam as duas tabelas podem ficar lentas sem um indice na coluna da chave estrangeira.',
);

$dir = __DIR__ . '/demo-project';
ProjectStorage::save($p, $dir);
$loaded = ProjectStorage::load($dir);

// 1) salvar e carregar nao perde nada
echo json_encode($p->toArray()) === json_encode($loaded->toArray()) ? "OK: ida e volta identicas\n" : "ERRO: dados diferentes\n";

// 2) rename nao muda o ID (base da comparacao de versoes)
$oldId = $patients->id;
$patients->name = 'pacientes';
echo $patients->id === $oldId ? "OK: rename manteve o ID ($oldId)\n" : "ERRO: ID mudou\n";
