<?php

namespace App\Support;

/**
 * Convierte un nombre escrito a mano en una "huella" comparable, para detectar
 * que dos textos escritos de forma distinta se refieren a lo mismo.
 *
 * Se usa en los catálogos que alimentan los usuarios (conceptos y categorías):
 * el resultado se guarda en la columna `nombre_normalizado`, que es única en la
 * base de datos. Así "MORTERO Cemex 50kg" y "mortero CEMEX 50 kilos" producen la
 * misma huella ("mortero cemex 50 kg") y no se crea un registro duplicado que
 * partiría en dos las gráficas de gastos.
 *
 * Importante: el resultado NO se muestra al usuario; en pantalla se usa siempre
 * el `nombre` original. Si se cambian estas reglas, hay que recalcular la columna
 * `nombre_normalizado` de los registros existentes.
 *
 * @package App\Support
 */
final class NormalizadorTexto
{
    /**
     * Unidades de medida y las formas en que se suelen escribir.
     * La clave es la forma canónica; los valores, sus variantes ya en minúsculas
     * y sin acentos (la limpieza de acentos ocurre antes de usar esta tabla).
     *
     * @var array<string, list<string>>
     */
    private const UNIDADES = [
        'kg' => ['kilogramos', 'kilogramo', 'kilos', 'kilo', 'kgs', 'kg'],
        'g' => ['gramos', 'gramo', 'grs', 'gr', 'g'],
        'ton' => ['toneladas', 'tonelada', 'tons', 'ton'],
        'ml' => ['mililitros', 'mililitro', 'ml'],
        'l' => ['litros', 'litro', 'lts', 'lt', 'l'],
        'm3' => ['metros cubicos', 'metro cubico', 'mts3', 'mt3', 'm3'],
        'm2' => ['metros cuadrados', 'metro cuadrado', 'mts2', 'mt2', 'm2'],
        'mm' => ['milimetros', 'milimetro', 'mm'],
        'cm' => ['centimetros', 'centimetro', 'cms', 'cm'],
        'm' => ['metros', 'metro', 'mts', 'mt', 'm'],
        'pulg' => ['pulgadas', 'pulgada', 'pulg'],
        'pza' => ['piezas', 'pieza', 'pzas', 'pza', 'pz'],
    ];

    /** Letras acentuadas y especiales → su versión simple. */
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
        '³' => '3', '²' => '2', // m³ → m3, m² → m2
    ];

    /**
     * Calcula la huella normalizada de un texto.
     *
     * Pasos (el orden importa):
     *  1. Minúsculas y sin acentos:            "Cemento GRIS Ñandú" → "cemento gris nandu"
     *  2. Coma decimal → punto:                "1,5 kg"            → "1.5 kg"
     *  3. Quitar símbolos (comillas, guiones…): "varilla 3/8''"     → "varilla 3/8"
     *     Se conservan letras, números, "/" (medidas como 3/8) y "." decimal.
     *  4. Separar número y unidad pegados:     "50kg"              → "50 kg"
     *  5. Unificar la unidad que sigue a un número: "50 kilos"     → "50 kg"
     *  6. Espacios repetidos → uno solo, sin espacios en los extremos.
     *
     * Las unidades solo se unifican cuando van después de un número, para no
     * alterar palabras normales ("metro" en "cinta metrica" no se toca).
     *
     * @param  string  $texto  Nombre tal como lo escribió el usuario.
     * @return string Huella normalizada (puede ser '' si el texto no tenía letras ni números).
     */
    public static function normalizar(string $texto): string
    {
        // 1. Minúsculas (respetando UTF-8) y sin acentos
        $texto = strtr(mb_strtolower($texto, 'UTF-8'), self::ACENTOS);

        // 2. "1,5" → "1.5" (solo entre dígitos, para no afectar listas "a, b")
        $texto = preg_replace('/(\d),(\d)/', '$1.$2', $texto);

        // 3. Cualquier carácter que no sea letra, número, "/" o "." pasa a espacio
        $texto = preg_replace('/[^a-z0-9\/.]+/', ' ', $texto);
        //    y los puntos que no estén entre dígitos (abreviaturas "pza.") también
        $texto = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $texto);

        // 4. Número pegado a letras: "50kg" → "50 kg" (no separa "m3": ahí la letra va primero)
        $texto = preg_replace('/(\d)([a-z])/', '$1 $2', $texto);

        // 5. Unidad canónica después de un número
        $texto = preg_replace_callback(self::patronUnidades(), function (array $m): string {
            return $m[1].' '.self::unidadCanonica($m[2]);
        }, $texto);

        // 6. Espacios
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * Expresión regular que encuentra "número + unidad", p. ej. "50 kilos" o "8 metros cubicos".
     * Las variantes se ordenan de la más larga a la más corta para que "metros cubicos"
     * gane frente a "metros". Se calcula una sola vez por petición.
     */
    private static function patronUnidades(): string
    {
        static $patron = null;

        if ($patron === null) {
            $variantes = array_merge(...array_values(self::UNIDADES));
            usort($variantes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            // En las variantes de dos palabras se acepta cualquier cantidad de espacios entre ellas
            $alternativas = array_map(
                fn (string $v): string => str_replace(' ', '\s+', preg_quote($v, '/')),
                $variantes,
            );

            // (\d)\s+ : la unidad debe seguir a un número · \b : y terminar como palabra completa
            $patron = '/(\d)\s+('.implode('|', $alternativas).')\b/';
        }

        return $patron;
    }

    /** Devuelve la forma canónica ("kg") de una variante encontrada ("kilos", "metros  cubicos"). */
    private static function unidadCanonica(string $variante): string
    {
        $variante = preg_replace('/\s+/', ' ', $variante);

        foreach (self::UNIDADES as $canonica => $variantes) {
            if (in_array($variante, $variantes, true)) {
                return $canonica;
            }
        }

        return $variante;
    }
}
