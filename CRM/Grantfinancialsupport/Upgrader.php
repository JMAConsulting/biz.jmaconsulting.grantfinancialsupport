<?php
use CRM_Grantfinancialsupport_Util as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Grantfinancialsupport_Upgrader extends CRM_Grantfinancialsupport_Upgrader_Base {

  public function install() {
    CRM_Core_BAO_ConfigSetting::enableComponent('CiviGrant');
  }

  public function postInstall() {
    E::updateFinancialAccountRelationship();
  }

  public function uninstall() {
    E::updateFinancialAccountRelationship('civicrm_grant', 'Grant Expense Account is', 'Expense Account is');
  }


  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   */

  public function upgrade_5100() {
    $this->ctx->log->info('Applying update 5100');
    E::updateFinancialAccountRelationship();
    return TRUE;
  }

}
