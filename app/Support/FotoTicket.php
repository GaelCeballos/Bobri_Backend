<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Guarda, borra y comparte las fotos de los tickets en el disco privado `tickets`.
 *
 * La foto NUNCA se guarda tal como llega. Se abre y se vuelve a guardar como WebP nuevo
 * (pesa ~30 % menos que un JPEG de calidad parecida: menos espacio en R2 y carga más rápido
 * en la pantalla de revisión; lo muestran todos los navegadores actuales):
 * - Se comprueba que sea una imagen real (por su contenido, no por la extensión ni por el
 *   tipo que declara el teléfono): un archivo disfrazado de .jpg no se puede abrir y se rechaza.
 * - El WebP nuevo no lleva metadatos EXIF: se descarta la ubicación GPS del teléfono, el modelo
 *   del equipo y la fecha. Antes se aplica la orientación para que la foto no quede acostada.
 * - Si es muy grande se reduce (lado mayor de 2000 px): el texto del ticket se sigue leyendo
 *   y se ahorra espacio en Cloudflare R2.
 * - El nombre es un UUID nuevo; el que manda el teléfono se ignora (no se puede adivinar la
 *   ruta de otra foto ni inyectar nombres como "../../.env").
 *
 * @package App\Support
 */
final class FotoTicket
{
    /** Disco de config/filesystems.php (local en desarrollo, R2 en producción) */
    public const DISCO = 'tickets';

    /** Lado mayor de la foto guardada, en píxeles */
    private const LADO_MAXIMO = 2000;

    /**
     * Píxeles máximos que se aceptan abrir (~16 MP). Una foto normal de celular tiene 12 MP;
     * el tope evita que una imagen enorme (o diseñada para inflarse al abrirla) agote la memoria.
     */
    private const PIXELES_MAXIMOS = 16_000_000;

    /** Calidad del WebP guardado: el texto pequeño del ticket sigue legible y pesa poco */
    private const CALIDAD_WEBP = 80;

    /**
     * Guarda la foto reprocesada y devuelve su ruta dentro del disco (`2026/10/<uuid>.webp`).
     *
     * @throws InvalidArgumentException Si no es una imagen JPEG, PNG o WebP válida, o es
     *                                  demasiado grande. El mensaje se puede mostrar al usuario.
     */
    public static function guardar(UploadedFile $archivo): string
    {
        $imagen = self::abrir($archivo->getRealPath());

        try {
            $imagen = self::reducir($imagen);
            $contenido = self::comoWebp($imagen);
        } finally {
            imagedestroy($imagen);
        }

        $ruta = now()->format('Y/m').'/'.Str::uuid().'.webp';
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        return $ruta;
    }

    /**
     * Borra la foto (al rechazar el ticket). No falla si ya no existe.
     */
    public static function borrar(?string $ruta): void
    {
        if ($ruta !== null && $ruta !== '') {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }

    /**
     * Enlace temporal firmado para ver la foto (solo se entrega al Administrador). Después de
     * `$minutos` deja de servir: si alguien lo copia o se filtra, caduca solo.
     */
    public static function urlTemporal(string $ruta, int $minutos = 15): string
    {
        return Storage::disk(self::DISCO)->temporaryUrl($ruta, now()->addMinutes($minutos));
    }

    /**
     * Abre la imagen según su contenido real y aplica la orientación EXIF.
     *
     * `getimagesize` lee la cabecera del archivo, no la extensión: así se sabe qué es en
     * realidad y su tamaño ANTES de cargarla completa en memoria.
     */
    private static function abrir(string $ruta): GdImage
    {
        $info = @getimagesize($ruta);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('El archivo no es una foto válida (JPG, PNG o WebP).');
        }

        if ($info[0] * $info[1] > self::PIXELES_MAXIMOS) {
            throw new InvalidArgumentException('La foto es demasiado grande. Tómala de nuevo o redúcela.');
        }

        $imagen = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($ruta),
            IMAGETYPE_PNG => @imagecreatefrompng($ruta),
            IMAGETYPE_WEBP => @imagecreatefromwebp($ruta),
        };

        // La cabecera puede parecer válida y el resto del archivo estar dañado o manipulado
        if (! $imagen instanceof GdImage) {
            throw new InvalidArgumentException('El archivo no es una foto válida (JPG, PNG o WebP).');
        }

        return $info[2] === IMAGETYPE_JPEG ? self::orientar($imagen, $ruta) : $imagen;
    }

    /**
     * Gira la foto según la etiqueta EXIF `Orientation` que guarda el teléfono.
     *
     * El teléfono guarda la foto "acostada" y anota cómo girarla; como el WebP nuevo no lleva
     * metadatos, el giro se aplica a los píxeles. Valores: 3 = 180°, 6 = 90° a la derecha,
     * 8 = 90° a la izquierda (los espejados, 2/4/5/7, no los produce una cámara normal).
     */
    private static function orientar(GdImage $imagen, string $ruta): GdImage
    {
        $exif = @exif_read_data($ruta);
        $grados = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($grados === 0) {
            return $imagen;
        }

        $girada = imagerotate($imagen, $grados, 0);
        imagedestroy($imagen);

        return $girada;
    }

    /**
     * Reduce la imagen si su lado mayor pasa de LADO_MAXIMO (conserva la proporción).
     */
    private static function reducir(GdImage $imagen): GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);

        if (max($ancho, $alto) <= self::LADO_MAXIMO) {
            return $imagen;
        }

        $reducida = $ancho >= $alto
            ? imagescale($imagen, self::LADO_MAXIMO)
            : imagescale($imagen, (int) round($ancho * self::LADO_MAXIMO / $alto), self::LADO_MAXIMO);
        imagedestroy($imagen);

        return $reducida;
    }

    /**
     * Codifica como WebP nuevo (sin metadatos). Un PNG o WebP con transparencia se pone sobre
     * fondo blanco: la foto se ve igual en cualquier fondo y el lector del ticket recibe una
     * imagen sin canal de transparencia.
     */
    private static function comoWebp(GdImage $imagen): string
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $imagen, 0, 0, 0, 0, $ancho, $alto);

        ob_start();
        imagewebp($lienzo, null, self::CALIDAD_WEBP);
        imagedestroy($lienzo);

        return (string) ob_get_clean();
    }
}
