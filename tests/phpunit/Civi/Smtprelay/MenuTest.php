<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * Administer > System Settings link: added once by this extension with a translatable label,
 * before the setting-admin mixin, which then skips its own (untranslated "%1 Settings") item.
 *
 * @group e2e
 */
class MenuTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  public function testSingleTranslatableSettingsLink(): void {
    $menu = [[
      'attributes' => ['name' => 'Administer', 'label' => 'Administer'],
      'child' => [['attributes' => ['name' => 'System Settings', 'label' => 'System Settings'], 'child' => []]],
    ]];
    \CRM_Utils_Hook::navigationMenu($menu);
    $links = array_values(array_filter($menu[0]['child'][0]['child'],
      fn($c) => strpos((string) ($c['attributes']['url'] ?? ''), 'civicrm/admin/setting/smtprelay') === 0));
    $this->assertCount(1, $links, 'Exactly one settings link, not ours plus the mixin one.');
    $this->assertSame(\CRM_Smtprelay_ExtensionUtil::ts('SMTP Relay settings'), $links[0]['attributes']['label']);
    $this->assertSame('administer CiviCRM,administer smtprelay', $links[0]['attributes']['permission']);
  }

}
