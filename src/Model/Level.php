<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

/** Os 3 modos: iniciante, intermediario e avancado. */
enum Level: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
}
