<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use SchemaGuard\Import\SqlImporter;
use SchemaGuard\Storage\ProjectStorage;

$sql = <<<'SQL'
-- exemplo misturando estilos PostgreSQL e MySQL
CREATE TABLE patients (
  id SERIAL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(255) UNIQUE,
  birth_date DATE
);
CREATE TABLE doctors (
  id INT AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id)
);
CREATE TABLE appointments (
  id SERIAL PRIMARY KEY,
  patient_id INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
  doctor_id INT NOT NULL,
  price NUMERIC(10,2) DEFAULT 0,
  status VARCHAR(20) DEFAULT 'scheduled',
  created_at TIMESTAMP DEFAULT now(),
  CONSTRAINT fk_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON UPDATE CASCADE
);
CREATE INDEX idx_appt_patient ON appointments (patient_id);
SQL;

$project = (new SqlImporter())->import($sql, 'Imported demo');

foreach ($project->tables as $t) {
    echo $t->name . "\n";
    foreach ($t->columns as $c) {
        printf("  %-11s %-8s %s%s%s%s%s\n", $c->name, $c->type,
            $c->length ? "({$c->length}) " : ($c->precision ? "({$c->precision},{$c->scale}) " : ''),
            $c->primaryKey ? 'PK ' : '', $c->nullable ? '' : 'NOT NULL ', $c->unique ? 'UNIQUE ' : '',
            ($c->autoIncrement ? 'AUTO ' : '') . ($c->default !== null ? "DEFAULT {$c->default}" : ''));
    }
    foreach ($t->indexes as $i) echo "  indice: " . ($i->name ?? '-') . "\n";
}
foreach ($project->relationships as $r) {
    $from = $project->findTable($r->fromTable);
    $to = $project->findTable($r->toTable);
    echo "{$from->name} -> {$to->name} [{$r->cardinality->value}] delete={$r->onDelete->value} update={$r->onUpdate->value}\n";
}

ProjectStorage::save($project, __DIR__ . '/demo-project');
echo "Salvo em examples/demo-project\n";
