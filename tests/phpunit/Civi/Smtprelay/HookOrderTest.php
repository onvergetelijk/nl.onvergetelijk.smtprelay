<?php

namespace Civi\Smtprelay;

use Civi\Core\Event\GenericHookEvent;
use Civi\Test\EndToEndInterface;

/**
 * The router must be the OUTERMOST mailer, also when another extension wraps the mailer on
 * hook_civicrm_alterMailer with the default priority (as oauth_outgoing_mail does). Otherwise a relayed
 * message would still pass through that wrapper - for oauth_outgoing_mail: to smtp.gmail.com with
 * XOAUTH2 instead of to the relay.
 *
 * Uses the real hook chain through CRM_Utils_Mail::createMailer(); the other extension is simulated
 * by a listener that is removed again in tearDown.
 *
 * @group e2e
 */
class HookOrderTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  /**
   * @var callable
   */
  private $otherWrapper;

  public function setUp(): void {
    parent::setUp();
    $this->otherWrapper = function (GenericHookEvent $e) {
      $e->mailer = new OtherExtensionWrapper($e->mailer);
    };
    \Civi::dispatcher()->addListener('hook_civicrm_alterMailer', $this->otherWrapper, 0);
  }

  public function tearDown(): void {
    \Civi::dispatcher()->removeListener('hook_civicrm_alterMailer', $this->otherWrapper);
    \Civi::settings()->revert('smtprelay_mode');
    parent::tearDown();
  }

  public function testRouterIsOutermostWhenOn(): void {
    \Civi::settings()->set('smtprelay_mode', 'on');
    $mailer = \CRM_Utils_Mail::createMailer();
    $this->assertInstanceOf(\CRM_Smtprelay_Router::class, $mailer, 'The router must be the outer layer.');
    $inner = (new \ReflectionProperty(\CRM_Smtprelay_Router::class, 'default'))->getValue($mailer);
    $this->assertInstanceOf(OtherExtensionWrapper::class, $inner, 'The other extension wraps the default mailer, inside the router.');
  }

  public function testOffLeavesOtherWrapperOutermost(): void {
    \Civi::settings()->set('smtprelay_mode', 'off');
    $this->assertInstanceOf(OtherExtensionWrapper::class, \CRM_Utils_Mail::createMailer());
  }

}

/**
 * Stand-in for another extension's mailer wrapper.
 */
class OtherExtensionWrapper extends \Mail {

  public $inner;

  public function __construct($inner) {
    $this->inner = $inner;
  }

  public function send($recipients, $headers, $body) {
    return TRUE;
  }

}
