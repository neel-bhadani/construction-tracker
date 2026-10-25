<?php

namespace App\Enums;

/**
 * A floor at a glance. Behind beats In progress and Not started: something on it is past its
 * planned date without an actual date.
 */
enum FloorStatus: string
{
    case Done = 'Done';
    case InProgress = 'In progress';
    case NotStarted = 'Not started';
    case Behind = 'Behind';
}
