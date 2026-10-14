<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

enum RefAction: string
{
    case NoAction = 'no_action';
    case Cascade = 'cascade';
    case Restrict = 'restrict';
    case SetNull = 'set_null';
    case SetDefault = 'set_default';
}
