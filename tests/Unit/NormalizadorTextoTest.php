<?php

namespace Tests\Unit;

use App\Support\NormalizadorTexto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de App\Support\NormalizadorTexto.
 *
 * Fijan el comportamiento del normalizador, del que dependen los catálogos de
 * conceptos y categorías: si la huella cambiara sin querer, conceptos que antes
 * coincidían dejarían de hacerlo y aparecerían duplicados en las gráficas.
 * El front replica este algoritmo en TypeScript para trabajar sin señal, así que
 * estos mismos casos sirven también para sus pruebas.
 *
 * Extiende el TestCase de PHPUnit (no el de Laravel) porque no necesita base de
 * datos ni arrancar el framework: así estas pruebas corren en milisegundos.
 */
final class NormalizadorTextoTest extends TestCase
{
    /**
     * La huella (`nombre_normalizado`) une las formas de escribir el MISMO nombre:
     * mayúsculas, acentos, símbolos, espacios y unidades ("50 kilos" = "50kg").
     * Como es única en la BD, cada caso aquí es un duplicado que el backend impide.
     *
     * @param string $entrada  Texto original escrito por el usuario.
     * @param string $esperado Resultado esperado después de la normalización.
     */
    #[DataProvider('casosNormalizar')]
    public function test_normalizar(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, NormalizadorTexto::normalizar($entrada));
    }

    /**
     * Casos de {@see test_normalizar()}: [texto escrito por el usuario, huella esperada].
     *
     * @return array<string, array{string, string}>
     */
    public static function casosNormalizar(): array
    {
        return [
            'minusculas y sin acentos' => ['Cemento GRIS Ñandú', 'cemento gris nandu'],
            'coma decimal entre digitos' => ['1,5 kg', '1.5 kg'],
            'quita comillas y conserva la diagonal' => ["varilla 3/8''", 'varilla 3/8'],
            'separa numero pegado a la unidad' => ['MORTERO Cemex 50kg', 'mortero cemex 50 kg'],
            'unifica kilos a kg' => ['mortero CEMEX 50 kilos', 'mortero cemex 50 kg'],
            'unidad de dos palabras' => ['Grava 8 metros   cubicos', 'grava 8 m3'],
            'superindice' => ['Arena 8 m³', 'arena 8 m3'],
            'punto decimal se conserva' => ['Tornillo 2.5 cm', 'tornillo 2.5 cm'],
            'punto de abreviatura se quita' => ['Pza. de block', 'pza de block'],
            'unidad sin numero no se toca' => ['Metro de madera', 'metro de madera'],
            'espacios repetidos y en los extremos' => ['  Renta   de rotomartillo  ', 'renta de rotomartillo'],
            'solo simbolos queda vacio' => ['---', ''],
        ];
    }

    /**
     * Variantes del mismo producto que la huella NO une deben compartir firma, para
     * que al crear la segunda el backend responda 409 "¿Quisiste decir…?" y aparezcan
     * juntas en el reporte de posibles duplicados del administrador.
     *
     * @param string $a      Primera variante del producto.
     * @param string $b      Segunda variante del producto.
     * @param string $firma  Firma esperada para ambas variantes.
     */
    #[DataProvider('casosMismoProducto')]
    public function test_palabras_clave_iguales_para_variantes_del_mismo_producto(string $a, string $b, string $firma): void
    {
        $this->assertSame($firma, NormalizadorTexto::palabrasClave($a));
        $this->assertSame($firma, NormalizadorTexto::palabrasClave($b));
    }

    /**
     * Casos de {@see test_palabras_clave_iguales_para_variantes_del_mismo_producto()}:
     * [variante A, variante B, firma que deben compartir].
     *
     * @return array<string, array{string, string, string}>
     */
    public static function casosMismoProducto(): array
    {
        return [
            'palabras vacias y orden' => ['Saco de cemento 25 kg', 'Cemento 25 kg', '25kg cemento'],
            'compra y plural' => ['Compra de pala', 'Palas', 'pala'],
            'plural en -s' => ['Tabiques rojos', 'Tabique rojo', 'rojo tabique'],
            'pulgadas se ignoran' => ['Tubo de PVC 4"', 'Tubo PVC 4 pulgadas', '4 pvc tubo'],
            'medidas con guion o x' => ['Malla 6-6', 'Malla 6x6', '6x6 malla'],
            // Ejemplos reales de captura: la cantidad comprada va en otro campo, no en el nombre
            'presentacion con de y saco' => ['Cemento de 50 kg', 'Saco de cemento 50 kilos', '50kg cemento'],
            'pago a una persona en cualquier orden' => ['Pago a fontanero Manuel', 'Manuel fontanero', 'fontanero manuel'],
            'pago a un servicio con o sin acento' => ['Pago a eléctrico', 'Pago a electrico', 'electrico'],
        ];
    }

    /**
     * Productos que se parecen pero son distintos NO deben compartir firma: si la
     * compartieran, el usuario recibiría un "¿Quisiste decir…?" falso al capturar.
     *
     * @param string $a  Primer producto.
     * @param string $b  Segundo producto.
     */
    #[DataProvider('casosProductosDistintos')]
    public function test_palabras_clave_distintas_para_productos_distintos(string $a, string $b): void
    {
        $this->assertNotSame(NormalizadorTexto::palabrasClave($a), NormalizadorTexto::palabrasClave($b));
    }

    /**
     * Casos de {@see test_palabras_clave_distintas_para_productos_distintos()}.
     *
     * @return array<string, array{string, string}>
     */
    public static function casosProductosDistintos(): array
    {
        return [
            // La cantidad del nombre es la presentación del producto: son productos distintos
            'otra presentacion' => ['Cemento 25 kg', 'Cemento 50 kg'],
            // Rentar la revolvedora no es comprarla
            'renta no es palabra vacia' => ['Renta de revolvedora', 'Revolvedora'],
            // Limitación conocida: los sinónimos ("eléctrico" / "electricista") NO se detectan;
            // el administrador los une con "fusionar". Si se agregan aliases (capa 4), este caso cambia
            'sinonimos no se detectan' => ['Pago a electrico', 'Pago al electricista'],
        ];
    }

    /**
     * La firma ordena las palabras alfabéticamente; por eso pega cada número con su
     * unidad antes de ordenar. Si no, "25 kg 50 piezas" y "50 kg 25 piezas" darían
     * la misma firma ("25 50 cemento kg pza") aunque sean productos distintos.
     */
    public function test_palabras_clave_conserva_a_que_numero_pertenece_cada_unidad(): void
    {
        $this->assertSame('25kg 50pza cemento', NormalizadorTexto::palabrasClave('Cemento 25 kg 50 piezas'));
    }
}
