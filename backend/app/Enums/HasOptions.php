<?php

namespace App\Enums;

/** Libellés français et liste d'options pour le front (valeur, libellé, ton de couleur). */
trait HasOptions
{
    abstract public function label(): string;

    public function tone(): string
    {
        return 'gray';
    }

    /** @return list<array{value: string, label: string, tone: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'tone' => $c->tone()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
