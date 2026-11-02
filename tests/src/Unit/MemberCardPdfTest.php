<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_member_success\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\makerspace_member_success\Service\MemberCardPdf;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_member_success\Service\MemberCardPdf
 * @group makerspace_member_success
 */
final class MemberCardPdfTest extends UnitTestCase {

  private MemberCardPdf $pdf;
  private string $fonts;

  protected function setUp(): void {
    parent::setUp();
    $this->pdf = new MemberCardPdf(
      $this->createMock(FileSystemInterface::class),
      $this->createMock(ModuleExtensionList::class),
    );
    $this->fonts = dirname(__DIR__, 3) . '/fonts';
  }

  /**
   * Short names get the full size; long names shrink to fit the band.
   */
  public function testFitSizeShrinksLongNames(): void {
    $xb = $this->fonts . '/Montserrat-ExtraBold.ttf';
    $this->assertSame(24.0, $this->pdf->fitSize('COREY', $xb, 24.0));
    $long = $this->pdf->fitSize('JEAN PIERRE ALEXANDRE', $xb, 24.0);
    $this->assertLessThan(24.0, $long);
    $this->assertGreaterThanOrEqual(6.0, $long);
  }

  /**
   * Names are uppercased and escaped; a missing photo leaves a placeholder.
   */
  public function testHtmlEscapesAndUppercases(): void {
    $html = $this->pdf->html([['first' => 'Zoë', 'last' => "O'Brien <b>", 'photo' => NULL]], $this->fonts);
    $this->assertStringContainsString('ZOË', $html);
    $this->assertStringContainsString('O&#039;BRIEN &lt;B&gt;', $html);
    $this->assertStringContainsString('class="nophoto"', $html);
  }

}
