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
     * Palabras que no distinguen un producto de otro (usadas por {@see palabrasClave()}).
     *
     * Se incluyen "compra" y "pago" porque todo gasto es una compra o un pago
     * ("Compra de pala" = "Pala"), pero NO "renta", porque sí cambia el
     * significado ("Renta de revolvedora" ≠ "Revolvedora").
     *
     * Las palabras se comparan ya en singular, por eso solo se listan en singular.
     *
     * @var list<string>
     */
    private const PALABRAS_VACIAS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'para', 'con', 'y', 'en', 'a', 'al', 'por',
        // "unos" se convierte en "uno" al pasar a singular, por eso aparece así
        'un', 'uno', 'una', 'unas', 'compra', 'pago',
        // Presentación del producto: ya se guarda en unidad_medida ("Saco de cemento 50 kg" = "Cemento 50 kg")
        'saco', 'bulto', 'costal', 'cubeta', 'bote', 'caja', 'rollo', 'paquete', 'bolsa',
    ];

    /**
     * Firma para DETECTAR POSIBLES duplicados que se escriben distinto.
     *
     * A diferencia de {@see normalizar()}, no se usa para unir registros
     * automáticamente: dos conceptos con la misma firma se le muestran al usuario
     * (409 al crear) o al administrador (reporte) como "posible duplicado", y una
     * persona decide. Por eso puede ser más agresiva: ignora el orden de las
     * palabras y las palabras vacías.
     *
     * Ejemplos:
     *  - "Saco de cemento 25 kg" y "Cemento 25 kg" → "25kg cemento"
     *  - "Compra de pala" y "Palas"                 → "pala"
     *  - "Tabiques rojos" y "Tabique rojo"          → "rojo tabique"
     *  - "Tubo de PVC 4\"" y "Tubo PVC 4 pulgadas"  → "4 pvc tubo"
     *  - "Malla 6-6" y "Malla 6x6"                   → "6x6 malla"
     *  - "Cemento 50 kg" → "50kg cemento" (otro producto: la cantidad importa)
     *
     * @param  string  $texto  Nombre tal como lo escribió el usuario.
     * @return string Palabras clave únicas, ordenadas alfabéticamente y separadas por espacio.
     */
    public static function palabrasClave(string $texto): string
    {
        // Unifica medidas tipo "6x6", "6 X 6", "6×6" o "6-6" → "6x6". Va antes de
        // normalizar() porque esta quita el guion y el signo ×
        $texto = preg_replace('/(\d)\s*[x×*-]\s*(\d)/iu', '$1x$2', $texto);
        $texto = self::normalizar($texto);
        // normalizar() separa número y letra ("6x6" → "6 x6"); aquí se vuelven a unir
        $texto = preg_replace('/(\d) x(\d)/', '$1x$2', $texto);

        // Ignora la unidad pulgada: en obra casi nunca se escribe ("varilla 3/8",
        // "tubo 4") y así "4 pulgadas" y 4" coinciden con "4"
        $texto = preg_replace('/\bpulg\b/', ' ', $texto);

        // Pega cada número con la unidad que lo sigue ("25 kg" → "25kg"): al ordenar las
        // palabras alfabéticamente, de otro modo el número y su unidad quedarían separados
        // y se perdería a qué número pertenece cada unidad ("25 kg 50 piezas" → "25kg 50pza")
        $unidades = implode('|', array_map(
            fn (string $u): string => preg_quote($u, '/'),
            array_keys(self::UNIDADES),
        ));
        $texto = preg_replace('/(\d)\s+('.$unidades.')\b/', '$1$2', $texto);

        // Singular, sin palabras vacías ni repetidas; el orden alfabético hace que el
        // orden en que se escribieron no importe
        $palabras = array_map(self::singular(...), explode(' ', $texto));
        $palabras = array_unique(array_diff($palabras, self::PALABRAS_VACIAS, ['']));
        sort($palabras, SORT_STRING);

        return implode(' ', $palabras);
    }

    /**
     * Pasa una palabra a singular de forma aproximada ("tabiques" → "tabique",
     * "tablones" → "tablon"), solo para {@see palabrasClave()}.
     *
     * No es una regla gramatical completa ("luces" → "luce"), y no hace falta: la
     * firma solo se compara contra otras firmas calculadas igual, y un error aquí
     * a lo mucho provoca una pregunta "¿Quisiste decir…?" de más, nunca una fusión.
     */
    private static function singular(string $palabra): string
    {
        // Solo palabras de 4 letras o más: números y medidas ("25kg", "3/8") y
        // palabras cortas ("gas", "mes") se dejan igual
        if (! preg_match('/^[a-z]{4,}$/', $palabra)) {
            return $palabra;
        }

        // "-es" después de l, n, r, d o j: "tablones" → "tablon", "rieles" → "riel"
        if (preg_match('/[lnrdj]es$/', $palabra)) {
            return substr($palabra, 0, -2);
        }

        // "-s" final: "palas" → "pala", "tabiques" → "tabique"
        return str_ends_with($palabra, 's') ? substr($palabra, 0, -1) : $palabra;
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
