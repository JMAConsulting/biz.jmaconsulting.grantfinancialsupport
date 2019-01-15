<?php

require_once 'grantfinancialsupport.civix.php';
use CRM_Grantfinancialsupport_Util as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function grantfinancialsupport_civicrm_config(&$config) {
  _grantfinancialsupport_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function grantfinancialsupport_civicrm_xmlMenu(&$files) {
  _grantfinancialsupport_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function grantfinancialsupport_civicrm_install() {
  _grantfinancialsupport_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function grantfinancialsupport_civicrm_postInstall() {
  _grantfinancialsupport_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function grantfinancialsupport_civicrm_uninstall() {
  _grantfinancialsupport_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function grantfinancialsupport_civicrm_enable() {
  _grantfinancialsupport_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function grantfinancialsupport_civicrm_disable() {
  _grantfinancialsupport_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function grantfinancialsupport_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _grantfinancialsupport_civix_civicrm_upgrade($op, $queue);
}

function grantfinancialsupport_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Grant_Form_Grant' && ($form->getVar('_action') != CRM_Core_Action::DELETE)) {
    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'GrantExtra.tpl',
    ));

    //Financial Type RG-125
    $financialType = CRM_Contribute_PseudoConstant::financialType();
    $result = civicrm_api3('EntityFinancialAccount', 'get', [
      'account_relationship' => ['IN' => ["Expense Account is", "Grant Expense Account is"]],
      'entity_table' => "civicrm_financial_type",
      'options' => ['limit' => 0],
    ])['values'];
    foreach ($result as $k => $v) {
      $typesWithExpenseAccounts[$v['entity_id']] = 1;
    }
    $financialType = array_intersect_key($financialType, $typesWithExpenseAccounts);
    if (count($financialType)) {
      $form->assign('financialType', $financialType);
    }
    $form->add('select', 'financial_type_id',
      ts('Financial Type'),
      array('' => ts('- select -')) + $financialType,
      FALSE
    );
    if ($form->getVar('_action') & CRM_Core_Action::ADD) {
      $form->setDefaults(['status_id' => CRM_Core_PseudoConstant::getKey('CRM_Grant_DAO_Grant', 'status_id', 'Paid')]);
    }
    E::buildPaymentBlock($form, FALSE);
    if (($form->getVar('_action') & CRM_Core_Action::UPDATE) && ($grantID = $form->getVar('_id'))) {
      $form->setDefaults(_setDefaultFinancialEntries($grantID));
    }
  }
}

function grantfinancialsupport_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName == 'CRM_Grant_Form_Grant') {
    if (!empty($fields['status_id'])) {
      $grantStatuses = CRM_Core_OptionGroup::values('grant_status');
      if (in_array($grantStatuses[$fields['status_id']], ['Paid', 'Withdrawn', 'Approved for Payment', 'Eligible']) && empty($fields['financial_type_id'])) {
        $errors['financial_type_id'] = ts('Financial Type is a required field');
      }
      if ($grantStatuses[$fields['status_id']] == 'Paid') {
        if (empty($fields['contribution_batch_id'])) {
          $errors['contribution_batch_id'] = ts('Batch is a required field');
        }
      }
    }
  }
}

function grantfinancialsupport_civicrm_pageRun(&$page) {
  if ($page->getVar('_name') == "CRM_Grant_Page_Tab" && ($grantID = $page->getVar('_id'))) {
    $values = _setDefaultFinancialEntries($grantID);
    $attributes = [
      'check_number' => ts('Check Number'),
      'trxn_date' => ts('Transaction Date'),
      'trxn_id' => ts('Transaction ID'),
      'contribution_batch_id' => ts('Batch'),
    ];
    if (!empty($values['contribution_batch_id'])) {
      $values['contribution_batch_id'] = CRM_Utils_Array::value($values['contribution_batch_id'], CRM_Contribute_PseudoConstant::batch());
    }
    $page->assign('financialFields', $attributes);
    $page->assign('financialValues', $values);
    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'PaymentDetails.tpl',
    ));
  }
}


function grantfinancialsupport_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName == 'Grant' && in_array($op, ['edit', 'create'])) {
    CRM_Core_Smarty::singleton()->assign('isCreate', FALSE);
    CRM_Core_Smarty::singleton()->assign('isUpdate', FALSE);
    CRM_Core_Smarty::singleton()->assign("grantParams", $params);
    if ($id) {
      // Store grant values.
      $previousGrant = civicrm_api3('Grant', 'getsingle', ['id' => $id]);
      CRM_Core_Smarty::singleton()->assign('previousGrant', $previousGrant);

      // Store multifund entries.
      $previousFund = _getFinancialEntries($id);
      CRM_Core_Smarty::singleton()->assign('previousFund', $previousFund);
      CRM_Core_Smarty::singleton()->assign('isUpdate', TRUE);
    }
    elseif (!empty($params['financial_type_id'])) {
      CRM_Core_Smarty::singleton()->assign('isCreate', TRUE);
    }
  }
}

function grantfinancialsupport_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'Grant' && $op == 'create') {
    $grantParams = CRM_Core_Smarty::singleton()->get_template_vars('grantParams');
    CRM_Core_Smarty::singleton()->assign("grantParams", NULL);
    if (!empty($grantParams) && CRM_Core_Smarty::singleton()->get_template_vars('isCreate')) {
      _createFinancialEntries(NULL, [
        'id' => $objectId,
        'contact_id' => $grantParams['contact_id'],
        'currency' => CRM_Core_Config::singleton()->defaultCurrency,
      ], $grantParams);
    }
    if (CRM_Core_Smarty::singleton()->get_template_vars('isUpdate')) {
      $grantParams['grant_id'] = $objectId;
      // Get previous grant values.
      $previousGrant = CRM_Core_Smarty::singleton()->get_template_vars('previousGrant');
      CRM_Core_Smarty::singleton()->assign('previousGrant', NULL);

      // Get previous multifund values.
      $previousFund = CRM_Core_Smarty::singleton()->get_template_vars('previousFund');
      CRM_Core_Smarty::singleton()->assign('previousFund', NULL);

      $grantStatuses = CRM_Core_OptionGroup::values('grant_status');
      $attributesChanged = [
        'statusChanged' => (!empty($grantParams['financial_type_id']) && ($grantParams['status_id'] != $previousGrant['status_id'])),
        'amountChanged' => (CRM_Utils_Rule::cleanMoney($previousGrant['amount_total']) != CRM_Utils_Rule::cleanMoney($grantParams['amount_total'])),
        'financialTypeChanged' => (!empty($grantParams['financial_type_id']) && ($previousGrant['financial_type_id'] != $grantParams['financial_type_id'])),
        'multifundChanged' => (!empty($previousFund['multifund_amount']) && !empty(array_diff_assoc($previousFund['multifund_amount'], array_filter($grantParams['multifund_amount'])))),
        'financialAccountChanged' => (!empty($previousFund['financial_account']) && !empty(array_diff_assoc($previousFund['financial_account'], array_filter($grantParams['financial_account'])))),
      ];
      if ($attributesChanged['statusChanged']) {
        if ($grantParams['status_id'] == array_search('Paid', $grantStatuses)) {
          $previousStatusID = ($previousGrant['status_id'] == array_search('Approved for Payment', $grantStatuses)) ? $previousGrant['status_id'] : NULL;
          _createFinancialEntries($previousStatusID, $previousGrant, $grantParams);
        }
        elseif ($grantParams['status_id'] == array_search('Withdrawn', $grantStatuses) ||
          $grantParams['status_id'] == array_search('Approved for Payment', $grantStatuses)) {
          _createFinancialEntries($previousGrant['status_id'], $previousGrant, $grantParams);
        }
      }
      if ($grantParams['status_id'] == array_search('Paid', $grantStatuses) && $attributesChanged['financialTypeChanged']) {
        $reverseAmount = -$previousGrant['amount_total'];
        $financialAccountId = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($previousGrant['financial_type_id'], 'Accounts Receivable Account is');
        _addFinancialEntries($financialAccountId, $reverseAmount, $grantParams, NULL, TRUE);
        // Add new entry
        $newFinancialAccountId = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($grantParams['financial_type_id'], 'Accounts Receivable Account is');
        _addFinancialEntries($newFinancialAccountId, $grantParams['amount_total'], $grantParams, NULL, TRUE);
      }
      if ($grantParams['status_id'] == array_search('Paid', $grantStatuses) && $attributesChanged['amountChanged']) {
        if (!count(array_filter($grantParams['multifund_amount']))) {
          $newAmount = $grantParams['amount_total'] - $previousGrant['amount_total'];
          $financialAccountId = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($grantParams['financial_type_id'], 'Accounts Receivable Account is');
          _addFinancialEntries($financialAccountId, $newAmount, $grantParams, NULL, TRUE);
        }
      }
      if ($grantParams['status_id'] == array_search('Paid', $grantStatuses) && $attributesChanged['financialAccountChanged']) {
        $itemsToBeReversed = array_diff_assoc($previousFund['financial_account'], array_filter($grantParams['financial_account']));
        foreach ($itemsToBeReversed as $key => $faId) {
          $reverseAmount = -$previousFund['multifund_amount'][$key];
          _addFinancialEntries($faId, $reverseAmount, $grantParams, NULL, TRUE);
          // Add new entry
          _addFinancialEntries($grantParams['financial_account'][$key], $grantParams['multifund_amount'][$key], $grantParams, NULL, TRUE);
          $keysToIgnore[] = $key;
        }
      }
      if ($grantParams['status_id'] == array_search('Paid', $grantStatuses) && $attributesChanged['multifundChanged']) {
        $itemsToBeReversed = array_diff_assoc($previousFund['multifund_amount'], array_filter($grantParams['multifund_amount']));
        foreach ($itemsToBeReversed as $key => $entry) {
          if (!empty($keysToIgnore) && in_array($key, $keysToIgnore)) {
            continue;
          }
          $newAmount = $grantParams['multifund_amount'][$key] - $previousFund['multifund_amount'][$key];
          _addFinancialEntries($grantParams['financial_account'][$key], $newAmount, $grantParams, NULL, TRUE);
        }
      }
      elseif (!empty($params['contribution_batch_id'])) {
        _updateBatchEntry($params['contribution_batch_id'], $id);
      }
    }
  }
  if ($objectName == 'Grant' && $op == 'delete') {
    E::deleteGrantFinancialEntries($objectId);
  }
}

function _updateBatchEntry($batchID, $grantID) {
  foreach (_setDefaultFinancialEntries($grantID, TRUE) as $ftID) {
    $result = civicrm_api3('EntityBatch', 'get', [
      'entity_table' => 'civicrm_financial_trxn',
      'entity_id' => $ftID['id'],
    ])['values'];
    if (!empty($result)) {
      foreach ($result as $id => $value) {
        if ($value['batch_id'] != $batchID) {
          civicrm_api3('EntityBatch', 'create', array_merge($value, ['batch_id' => $batchID]));
        }
      }
    }
    else {
      civicrm_api3('EntityBatch', 'create', [
        'entity_table' => 'civicrm_financial_trxn',
        'entity_id' => $ftID['id'],
        'batch_id' => $batchID,
      ]);
    }
  }
}

function _addFinancialEntries($newFFAID, $newAmount, $params, $financialTrxn, $addFT = FALSE) {
  if ($addFT) {
    $financialTrxn = civicrm_api3('FinancialTrxn', 'create', [
      'total_amount' => $newAmount,
      'to_financial_account_id' => CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['financial_type_id'], 'Asset Account is') ?: E::getAssetFinancialAccountID(),
      'status_id' => 'Completed',
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_DAO_Contribution', 'payment_instrument_id', 'Check'),
      'check_number' => CRM_Utils_Array::value('check_number', $params),
      'trxn_id' => CRM_Utils_Array::value('trxn_id', $params),
      'trxn_date' => CRM_Utils_Array::value('trxn_date', $params, date('YmdHis')),
      'entity_id' => CRM_Utils_Array::value('grant_id', $params, NULL),
      'entity_table' => 'civicrm_grant',
      'is_payment' => 1,
    ]);
  }
  $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
  $itemParams = array(
    'transaction_date' => date('YmdHis'),
    'contact_id' => $params['contact_id'],
    'currency' => $params['currency'],
    'amount' => $newAmount,
    'description' => CRM_Utils_Array::value('description', $params),
    'status_id' => array_search('Completed', $contributionStatuses),
    'financial_account_id' => $newFFAID,
    'entity_table' => 'civicrm_grant',
    'entity_id' => $params['grant_id'],
  );
  $trxnIds['id'] = $financialTrxn['id'];
  $financialItemID = CRM_Financial_BAO_FinancialItem::create($itemParams, NULL, $trxnIds)->id;
}

function _setDefaultFinancialEntries($grantID, $getFTIDs = FALSE) {
  $select = "SELECT ft.check_number, ft.trxn_date, ft.trxn_id, b.id as contribution_batch_id, fi.description";
  if ($getFTIDs) {
    $select = "SELECT DISTINCT ft.id ";
  }
  $sql = " {$select}
    FROM civicrm_entity_financial_trxn eft
    INNER JOIN civicrm_financial_trxn ft ON eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_grant' AND eft.entity_id = $grantID
    LEFT JOIN civicrm_entity_financial_trxn eft1 ON eft1.financial_trxn_id = ft.id AND eft1.entity_table = 'civicrm_financial_item'
    LEFT JOIN civicrm_financial_item fi ON eft1.entity_id = fi.id
    LEFT JOIN civicrm_entity_batch eb ON eb.entity_table ='civicrm_financial_trxn' AND eb.entity_id = ft.id
    LEFT JOIN civicrm_batch b ON b.id = eb.batch_id

  ";
  if (!$getFTIDs) {
    $sql .= ' ORDER BY eft.id DESC LIMIT 1';
    return CRM_Utils_Array::value(0, CRM_Core_DAO::executeQuery($sql)->fetchAll(), []);
  }
  else {
    $sql .= ' GROUP BY ft.id ';
    return CRM_Core_DAO::executeQuery($sql)->fetchAll();
  }

}

function _createFinancialEntries($previousStatusID, $grantParams, $params) {
  $grantStatuses = CRM_Core_OptionGroup::values('grant_status');
  $multiEntries = _processMultiFundEntries($params);
  $amount = $params['amount_total'];
  $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
  $financialItemStatus = CRM_Core_PseudoConstant::accountOptionValues('financial_item_status');
  $currentStatusID = $params['status_id'];
  $createItem = TRUE;
  $trxnParams = [];
  $financialItemStatusID = array_search('Paid', $financialItemStatus);
  if ($currentStatusID == array_search('Approved for Payment', $grantStatuses)) {
    $trxnParams['to_financial_account_id'] = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['financial_type_id'], 'Accounts Receivable Account is');
    $financialItemStatusID = array_search('Unpaid', $financialItemStatus);
    $trxnParams['status_id'] = array_search('Pending', $contributionStatuses);
  }
  elseif ($currentStatusID == array_search('Paid', $grantStatuses)) {
    $trxnParams['to_financial_account_id'] = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['financial_type_id'], 'Asset Account is') ?: E::getAssetFinancialAccountID();
    $trxnParams['status_id'] = array_search('Completed', $contributionStatuses);
    $createItem = empty($previousStatusID);
  }
  elseif ($currentStatusID == array_search('Withdrawn', $grantStatuses)) {
    $trxnParams['to_financial_account_id'] = E::getAssetFinancialAccountID();
    $trxnParams['from_financial_account_id'] = CRM_Core_DAO::singleValueQuery("
    SELECT to_financial_account_id FROM civicrm_financial_trxn  cft
      INNER JOIN civicrm_entity_financial_trxn ecft ON ecft.financial_trxn_id = cft.id
    WHERE  ecft.entity_id = " . $grantParams['id'] . " and ecft.entity_table = 'civicrm_grant'
    ORDER BY cft.id DESC LIMIT 1");
    $trxnParams['status_id'] = array_search('Cancelled', $contributionStatuses);
    $financialItemStatusID = array_search('Unpaid', $financialItemStatus);
    $amount = -$amount;
  }

  //build financial transaction params
  $trxnParams = array_merge($trxnParams, array(
    'trxn_date' => date('YmdHis'),
    'currency' => $grantParams['currency'],
    'entity_table' => 'civicrm_grant',
    'entity_id' => $grantParams['id'],
  ));

  if (empty($multiEntries)) {
    if ($previousStatusID == array_search('Approved for Payment', $grantStatuses) &&
      $currentStatusID == array_search('Paid', $grantStatuses)
    ) {
      $multiEntries[] = [
        'from_financial_account_id' => CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['financial_type_id'], ['IN' => ['Grant Expense Account is', 'Expense Account is']]),
        'total_amount' => $amount,
      ];
    }
    else {
      $multiEntries[] = [
        'from_financial_account_id' => CRM_Utils_Array::value('from_financial_account_id', $trxnParams),
        'total_amount' => $amount,
      ];
    }
  }

  // Create financial trxn.
  if ($currentStatusID == array_search('Paid', $grantStatuses)) {
    $trxnParams['total_amount'] = $amount;
    $trxnParams['payment_instrument_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_DAO_Contribution', 'payment_instrument_id', 'Check');
    $trxnParams['check_number'] = CRM_Utils_Array::value('check_number', $params);
    $trxnParams['trxn_id'] = CRM_Utils_Array::value('trxn_id', $params);
    $trxnParams['is_payment'] = 1;
    $trxnId = civicrm_api3('FinancialTrxn', 'create', $trxnParams);
    civicrm_api3('EntityBatch', 'create', [
      'entity_table' => 'civicrm_financial_trxn',
      'entity_id' => $trxnId['id'],
      'batch_id' => $params['contribution_batch_id'],
    ]);
  }

  $financialItemID = NULL;
  foreach ($multiEntries as $key => $entry) {

    if ($currentStatusID == array_search('Paid', $grantStatuses)) {
      E::processPaymentDetails([
        'trxn_id' => $params['trxn_id'],
        'financial_trxn_id' => $trxnId['id'],
        'check_number' => $params['check_number'],
        'description' => CRM_Utils_Array::value('description', $params),
      ]);
    }

    if ($createItem) {
      $financialAccountId = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($params['financial_type_id'], 'Accounts Receivable Account is');
      if (!empty($entry['from_financial_account_id'])) {
        $financialAccountId = $entry['from_financial_account_id'];
      }
      $itemParams = array(
        'transaction_date' => date('YmdHis'),
        'contact_id' => $grantParams['contact_id'],
        'currency' => $grantParams['currency'],
        'amount' => $entry['total_amount'],
        'description' => CRM_Utils_Array::value('description', $params),
        'status_id' => $financialItemStatusID,
        'financial_account_id' => $financialAccountId,
        'entity_table' => 'civicrm_grant',
        'entity_id' => $grantParams['id'],
      );
      $trxnIds['id'] = $trxnId['id'];
      $financialItemID = CRM_Financial_BAO_FinancialItem::create($itemParams, NULL, $trxnIds)->id;
    }
  }
}

function _processMultiFundEntries($values) {
  $multifundEntries = [];
  if (empty($values['multifund_amount'])) {
    return $multifundEntries;
  }
  for ($i = 0; $i < 10; $i++) {
    if (!empty($values['financial_account'][$i])) {
      $multifundEntries[$i] = [
        'from_financial_account_id' => $values['financial_account'][$i],
        'total_amount' => $values['multifund_amount'][$i],
      ];
    }
  }

  return $multifundEntries;
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function grantfinancialsupport_civicrm_managed(&$entities) {
  _grantfinancialsupport_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function grantfinancialsupport_civicrm_caseTypes(&$caseTypes) {
  _grantfinancialsupport_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function grantfinancialsupport_civicrm_angularModules(&$angularModules) {
  _grantfinancialsupport_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function grantfinancialsupport_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _grantfinancialsupport_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function grantfinancialsupport_civicrm_entityTypes(&$entityTypes) {
  _grantfinancialsupport_civix_civicrm_entityTypes($entityTypes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function grantfinancialsupport_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function grantfinancialsupport_civicrm_navigationMenu(&$menu) {
  _grantfinancialsupport_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _grantfinancialsupport_civix_navigationMenu($menu);
} // */
