<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * Every translatable string in the code is in the template (.pot) and translated in every shipped
 * language (.po and the compiled .mo). A new string without a translation shows up as English in an
 * otherwise translated screen - that happened with the page title on 15-11-2026.
 *
 * Strings are collected from: $t('...') in the settings file, E::ts('...') in PHP, {ts}...{/ts} in
 * templates.
 *
 * @group e2e
 */
class TranslationTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private function root(): string {
    return dirname(__DIR__, 4);
  }

  private function codeStrings(): array {
    $root    = $this->root();
    $strings = [];
    $php     = array_merge(glob("$root/*.php"), glob("$root/settings/*.php"), glob("$root/CRM/Smtprelay/*.php"), glob("$root/CRM/Smtprelay/*/*.php"));
    foreach ($php as $file) {
      preg_match_all("/(?:\\\$t|E::ts)\\('((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($file), $m);
      foreach ($m[1] as $s) {
        $strings[] = stripslashes($s);
      }
    }
    foreach (glob("$root/templates/CRM/Smtprelay/*/*.tpl") as $file) {
      preg_match_all('/\{ts\}(.*?)\{\/ts\}/s', file_get_contents($file), $m);
      $strings = array_merge($strings, $m[1]);
    }
    return array_values(array_unique($strings));
  }

  /** msgid => msgstr from a .po/.pot file (single-line entries, as written by this extension). */
  private function poEntries(string $file): array {
    preg_match_all('/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', file_get_contents($file), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $e) {
      if ($e[1] !== '') {
        $out[stripcslashes($e[1])] = stripcslashes($e[2]);
      }
    }
    return $out;
  }

  public function testCodeStringsAreFound(): void {
    $strings = $this->codeStrings();
    $this->assertGreaterThan(40, count($strings), 'the string collector found too little; is the pattern still right?');
    $this->assertContains('SMTP Relay settings', $strings);
  }

  public function testEveryStringIsInTheTemplate(): void {
    $pot = $this->poEntries($this->root() . '/l10n/smtprelay.pot');
    $missing = array_diff($this->codeStrings(), array_keys($pot));
    $this->assertSame([], array_values($missing), 'strings missing from l10n/smtprelay.pot');
  }

  public function testEveryLanguageTranslatesEveryString(): void {
    require_once 'PHPgettext/streams.php';
    require_once 'PHPgettext/gettext.php';
    $languages = glob($this->root() . '/l10n/*/LC_MESSAGES/smtprelay.po');
    $this->assertNotEmpty($languages, 'no translations shipped');
    foreach ($languages as $poFile) {
      $lang = basename(dirname($poFile, 2));
      $po   = $this->poEntries($poFile);
      $mo   = new \gettext_reader(new \FileReader(substr($poFile, 0, -3) . '.mo'));
      foreach ($this->codeStrings() as $s) {
        $this->assertNotEmpty($po[$s] ?? '', "$lang: no translation in the .po for: $s");
        $this->assertSame($po[$s], $mo->translate($s), "$lang: the .mo is out of date for: $s");
      }
    }
  }

}
