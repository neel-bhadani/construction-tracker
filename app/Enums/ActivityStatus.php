<?php

namespace App\Enums;

enum ActivityStatus: string
{
    case Done = 'Done';
    case Next = 'NEXT';
    case Pending = 'Pending';
}
