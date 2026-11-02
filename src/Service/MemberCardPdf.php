<?php

declare(strict_types=1);

namespace Drupal\makerspace_member_success\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;

/**
 * Renders printable member ID cards as a PDF.
 *
 * One page per card, sized to a CR80 card in portrait (2.125in x 3.375in),
 * full bleed, matching the cards staff have been printing by hand: the
 * member photo fills the top, a brand-red band at the bottom carries the
 * first name large and the last name small, both uppercase. Send the PDF
 * straight to the card printer at 100% / "actual size".
 */
final class MemberCardPdf {

  /**
   * CR80 portrait in PDF points (72/in).
   */
  public const PAGE_WIDTH = 153.0;
  public const PAGE_HEIGHT = 243.0;

  /**
   * Height of the photo area; the red band takes the rest.
   */
  public const PHOTO_HEIGHT = 176.0;

  /**
   * Brand primary (tokens.css --mh-color-brand-primary).
   */
  public const BAND_COLOR = '#8b1919';

  /**
   * Usable text width inside the band, in points.
   */
  private const TEXT_WIDTH = 135.0;

  private const FIRST_NAME_MAX = 24.0;
  private const LAST_NAME_MAX = 12.0;

  /**
   * Photo raster size: 300 dpi across the photo area.
   */
  private const PHOTO_PX_WIDTH = 640;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Builds a PDF with one page per card.
   *
   * @param array<int, array{first: string, last: string, photo: ?string}> $cards
   *   Each card: first name, last name, and a local/stream path to the photo
   *   (NULL renders a grey placeholder).
   *
   * @return string
   *   The PDF bytes.
   */
  public function render(array $cards): string {
    $fontDir = $this->fontDirectory();
    $cacheDir = $this->fileSystem->getTempDirectory() . '/makerspace_member_card_fonts';
    $this->fileSystem->prepareDirectory($cacheDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $options = new Options();
    $options->setIsRemoteEnabled(FALSE);
    $options->setChroot([$fontDir]);
    $options->setFontDir($cacheDir);
    $options->setFontCache($cacheDir);
    $options->setTempDir($cacheDir);
    $options->setDpi(300);
    $options->setDefaultFont('DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->setPaper([0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT]);
    $dompdf->loadHtml($this->html($cards, $fontDir));
    $dompdf->render();
    return (string) $dompdf->output();
  }

  /**
   * Builds the HTML for all cards.
   */
  public function html(array $cards, string $fontDir): string {
    $pages = [];
    foreach ($cards as $card) {
      $pages[] = $this->cardHtml($card, $fontDir);
    }

    $w = self::PAGE_WIDTH;
    $h = self::PAGE_HEIGHT;
    $ph = self::PHOTO_HEIGHT;
    $band = $h - $ph;
    $color = self::BAND_COLOR;
    $xb = $fontDir . '/Montserrat-ExtraBold.ttf';
    $b = $fontDir . '/Montserrat-Bold.ttf';

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
@font-face { font-family: 'MH Card XB'; src: url('{$xb}') format('truetype'); font-weight: normal; font-style: normal; }
@font-face { font-family: 'MH Card B'; src: url('{$b}') format('truetype'); font-weight: normal; font-style: normal; }
@page { size: {$w}pt {$h}pt; margin: 0; }
html, body { margin: 0; padding: 0; }
.card { position: relative; width: {$w}pt; height: {$h}pt; overflow: hidden; page-break-after: always; }
.card.last { page-break-after: auto; }
.photo { position: absolute; top: 0; left: 0; width: {$w}pt; height: {$ph}pt; }
.nophoto { position: absolute; top: 0; left: 0; width: {$w}pt; height: {$ph}pt; background: #d9d9d9; }
.band { position: absolute; top: {$ph}pt; left: 0; width: {$w}pt; height: {$band}pt; background: {$color}; color: #fff; text-align: center; }
.first { font-family: 'MH Card XB'; color: #fff; white-space: nowrap; }
.last { font-family: 'MH Card B'; color: #fff; white-space: nowrap; }
</style></head><body>
HTML
      . implode('', $pages)
      . '</body></html>';
  }

  /**
   * One card page.
   */
  private function cardHtml(array $card, string $fontDir): string {
    $first = mb_strtoupper(trim($card['first'] ?? ''));
    $last = mb_strtoupper(trim($card['last'] ?? ''));

    $firstSize = $this->fitSize($first, $fontDir . '/Montserrat-ExtraBold.ttf', self::FIRST_NAME_MAX);
    $lastSize = $this->fitSize($last, $fontDir . '/Montserrat-Bold.ttf', self::LAST_NAME_MAX);

    // Center the two lines vertically in the band.
    $band = self::PAGE_HEIGHT - self::PHOTO_HEIGHT;
    $firstLine = $first === '' ? 0.0 : $firstSize * 1.05;
    $lastLine = $last === '' ? 0.0 : $lastSize * 1.15;
    $gap = ($first !== '' && $last !== '') ? 1.0 : 0.0;
    $top = max(2.0, ($band - $firstLine - $gap - $lastLine) / 2);

    $photo = $this->photoDataUri($card['photo'] ?? NULL);
    $photoHtml = $photo !== NULL
      ? '<img class="photo" src="' . $photo . '">'
      : '<div class="nophoto"></div>';

    $html = '<div class="card">' . $photoHtml . '<div class="band">';
    if ($first !== '') {
      $html .= sprintf(
        '<div class="first" style="position:absolute;left:0;width:%1$.2fpt;top:%2$.2fpt;font-size:%3$.2fpt;line-height:%4$.2fpt;">%5$s</div>',
        self::PAGE_WIDTH, $top, $firstSize, $firstLine, htmlspecialchars($first, ENT_QUOTES, 'UTF-8')
      );
    }
    if ($last !== '') {
      $html .= sprintf(
        '<div class="last" style="position:absolute;left:0;width:%1$.2fpt;top:%2$.2fpt;font-size:%3$.2fpt;line-height:%4$.2fpt;">%5$s</div>',
        self::PAGE_WIDTH, $top + $firstLine + $gap, $lastSize, $lastLine, htmlspecialchars($last, ENT_QUOTES, 'UTF-8')
      );
    }
    return $html . '</div></div>';
  }

  /**
   * Largest font size (pt) up to $max that fits the band width.
   */
  public function fitSize(string $text, string $fontFile, float $max): float {
    if ($text === '') {
      return $max;
    }
    $width = $this->textWidth($text, $fontFile, 100.0);
    if ($width <= 0) {
      return $max;
    }
    $fit = self::TEXT_WIDTH * 100.0 / $width;
    return round(max(6.0, min($max, $fit)), 2);
  }

  /**
   * Width in points of $text set at $size points.
   */
  private function textWidth(string $text, string $fontFile, float $size): float {
    if (function_exists('imagettfbbox') && is_readable($fontFile)) {
      // GD sizes are points at 96 dpi; convert the pixel box back to points.
      $box = @imagettfbbox($size, 0, $fontFile, $text);
      if (is_array($box)) {
        return (max($box[2], $box[4]) - min($box[0], $box[6])) * 72 / 96;
      }
    }
    // Fallback: Montserrat bold capitals average ~0.75em.
    return mb_strlen($text) * $size * 0.75;
  }

  /**
   * Crops the photo to the card's photo area and returns a JPEG data URI.
   */
  private function photoDataUri(?string $path): ?string {
    if ($path === NULL || $path === '') {
      return NULL;
    }
    $real = $this->fileSystem->realpath($path) ?: $path;
    $bytes = @file_get_contents($real);
    if ($bytes === FALSE || $bytes === '') {
      return NULL;
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === FALSE) {
      return NULL;
    }
    $src = $this->applyExifOrientation($src, $bytes);

    $sw = imagesx($src);
    $sh = imagesy($src);
    $targetRatio = self::PAGE_WIDTH / self::PHOTO_HEIGHT;
    if ($sw / $sh > $targetRatio) {
      // Too wide: crop the sides evenly.
      $cw = (int) round($sh * $targetRatio);
      $ch = $sh;
      $cx = (int) floor(($sw - $cw) / 2);
      $cy = 0;
    }
    else {
      // Too tall: keep more of the top, where the face usually is.
      $cw = $sw;
      $ch = (int) round($sw / $targetRatio);
      $cx = 0;
      $cy = (int) floor(($sh - $ch) * 0.3);
    }

    $ow = self::PHOTO_PX_WIDTH;
    $oh = (int) round($ow / $targetRatio);
    $out = imagecreatetruecolor($ow, $oh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, $cx, $cy, $ow, $oh, $cw, $ch);

    ob_start();
    imagejpeg($out, NULL, 90);
    $jpeg = (string) ob_get_clean();
    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
  }

  /**
   * Rotates phone photos that rely on the EXIF orientation flag.
   */
  private function applyExifOrientation(\GdImage $img, string $bytes): \GdImage {
    if (!function_exists('exif_read_data') || !str_starts_with($bytes, "\xFF\xD8")) {
      return $img;
    }
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
    $angle = match ((int) ($exif['Orientation'] ?? 1)) {
      3 => 180,
      6 => -90,
      8 => 90,
      default => 0,
    };
    if ($angle === 0) {
      return $img;
    }
    $rotated = imagerotate($img, $angle, 0);
    return $rotated === FALSE ? $img : $rotated;
  }

  /**
   * Absolute path of the bundled fonts.
   */
  public function fontDirectory(): string {
    return DRUPAL_ROOT . '/' . $this->moduleList->getPath('makerspace_member_success') . '/fonts';
  }

}
