<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

enum Cardinality: string
{
    case OneToOne = 'one_to_one';
    case OneToMany = 'one_to_many';
    case ManyToOne = 'many_to_one';
    case ManyToMany = 'many_to_many'; // no desenho; na exportacao vira tabela intermediaria
}
