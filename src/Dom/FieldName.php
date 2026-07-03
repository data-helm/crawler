<?php

namespace DataHelm\Crawler\Dom;

/**
 * Turns a human label into a stable snake_case field name.
 * e.g. "1ª Praça" => "1_praca", "Valor de Avaliação" => "valor_de_avaliacao".
 */
final class FieldName
{
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    public static function fromLabel(string $label): string
    {
        $value = mb_strtolower(trim($label));
        $value = str_replace(['ª', 'º', '°'], '', $value);
        $value = strtr($value, self::ACCENTS);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');

        return $value !== '' ? $value : 'field';
    }
}
