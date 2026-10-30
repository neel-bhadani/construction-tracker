<?php

namespace App\Enums;

enum BuildingType: string
{
    case Building = 'building';
    case CommonWork = 'common_work';

    public function label(): string
    {
        return match ($this) {
            self::Building => 'Building',
            self::CommonWork => 'Common activities',
        };
    }
}
