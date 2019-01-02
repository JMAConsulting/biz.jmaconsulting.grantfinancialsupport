<?php

class CRM_Grantfinancialsupport_Util {

  public static function buildPaymentBlock($form, $print = TRUE) {
    $paymentFields = self::getPaymentFields($print);
    $form->assign('paymentFields', $paymentFields);
    foreach ($paymentFields as $name => $paymentField) {
      if (!empty($paymentField['add_field'])) {
        $attributes = array(
          'entity' => 'FinancialTrxn',
          'name' => $name,
          'context' => 'create',
          'action' => 'create',
        );
        $form->addField($name, $attributes, $paymentField['is_required']);
      }
      else {
        $form->add($paymentField['htmlType'],
          $name,
          $paymentField['title'],
          $paymentField['attributes'],
          $paymentField['is_required']
        );
      }
    }
  }

  public static function deleteGrantFinancialEntries($grantID) {
    $sql = "SELECT fi.id as fi_id, GROUP_CONCAT(DISTINCT ft.id) as ft_id, eb.batch_id
      FROM civicrm_entity_financial_trxn eft
      INNER JOIN civicrm_financial_trxn ft ON eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_grant' AND eft.entity_id = $grantID
      LEFT JOIN civicrm_entity_financial_trxn eft1 ON eft1.financial_trxn_id = ft.id AND eft1.entity_table = 'civicrm_financial_item'
      LEFT JOIN civicrm_financial_item fi ON eft1.entity_id = fi.id
      LEFT JOIN civicrm_entity_batch eb ON eb.entity_table ='civicrm_financial_trxn' AND eb.entity_id = ft.id
      LEFT JOIN civicrm_batch b ON b.id = eb.batch_id
      GROUP BY eft.entity_id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while($dao->fetch()) {
      $ftIDs = explode(',', $dao->ft_id);
      foreach ($ftIDs as $id) {
        civicrm_api3('FinancialTrxn', 'delete', ['id' => $id]);
      }
      if ($dao->fi_id) {
        civicrm_api3('FinancialItem', 'delete', ['id' => $dao->fi_id]);
        if (CRM_Core_DAO::singleValueQuery("show tables like 'civicrm_payment'") == 'civicrm_payment') {
          CRM_Core_DAO::executeQuery("DELETE FROM civicrm_payment WHERE financial_trxn_id IN ($dao->ft_id)");
        }
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_entity_financial_trxn WHERE financial_trxn_id IN ($dao->ft_id)");
      }
      if ($dao->batch_id) {
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_entity_batch WHERE entity_id IN ($dao->ft_id) AND entity_table = 'civicrm_financial_trxn' AND batch_id = $dao->batch_id ");
      }
    }
  }

  /**
   * Get payment fields
   */
  public static function getPaymentFields($print) {
    return array(
      'check_number' => array(
        'is_required' => $print,
        'add_field' => TRUE,
      ),
      'trxn_id' => array(
        'add_field' => TRUE,
        'is_required' => FALSE,
      ),
      'description' => array(
        'htmlType' => 'textarea',
        'name' => 'description',
        'title' => ts('Payment reason'),
        'is_required' => FALSE,
        'attributes' => [],
      ),
      'trxn_date' => array(
        'htmlType' => 'datepicker',
        'name' => 'trxn_date',
        'title' => ts('Payment date to appear on cheques'),
        'is_required' => $print,
        'attributes' => array(
          'date' => 'yyyy-mm-dd',
          'time' => 24,
          'context' => 'create',
          'action' => 'create',
        ),
      ),
      'contribution_batch_id' => [
        'htmlType' => 'select',
        'name' => 'contribution_batch_id',
        'title' => ts('Assign to Batch'),
        'attributes' => ['' => ts('None')] + CRM_Utils_Array::collect('title', civicrm_api3('Batch', 'get', ['status_id' => 'Open'])['values']),
        'is_required' => $print,
      ],
    );
  }

  public static function getAssetFinancialAccountID() {
    $relationTypeId = key(CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL, " AND v.name LIKE 'Asset' "));
    return CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_financial_account WHERE is_default = 1 AND financial_account_type_id = %1",
      [1 => [$relationTypeId, 'Integer']]
    );
  }

  public static function processPaymentDetails($params, $updateTrxn = TRUE) {
    $trxnID = $params['financial_trxn_id'];
    civicrm_api3('EntityBatch', 'create', [
      'entity_table' => 'civicrm_financial_trxn',
      'entity_id' => $trxnID,
      'batch_id' => $params['batch_id'],
    ]);

    if ($updateTrxn) {
      civicrm_api3('FinancialTrxn', 'create', [
        'id' => $trxnID,
        'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_DAO_Contribution', 'payment_instrument_id', 'Check'),
        'check_number' => CRM_Utils_Array::value('check_number', $params),
        'trxn_id' => CRM_Utils_Array::value('trxn_id', $params),
        'trxn_date' => CRM_Utils_Array::value('trxn_date', $params, date('YmdHis')),
      ]);
    }

    if (class_exists('CRM_Grant_BAO_GrantPayment')) {
      $grantPaymentRecord = [
        'financial_trxn_id' => $trxnID,
        'payment_created_date' => date('Y-m-d'),
        'payment_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Grant_DAO_GrantPayment', 'payment_status_id', 'Printed'),
        'payment_reason' => CRM_Utils_Array::value('description', $params),
      ];
      CRM_Grant_BAO_GrantPayment::add($grantPaymentRecord);
      return $grantPaymentRecord;
    }
  }

  public static function updateFinancialAccountRelationship(
    $entityTable = 'civicrm_grant',
    $oldAccountRelationship = 'Expense Account is',
    $newAccountRelationship = 'Grant Expense Account is'
  ) {
    $grantExpenseAccountID = civicrm_api3('OptionValue', 'getvalue', [
      'option_group_id' => 'account_relationship',
      'name' => $newAccountRelationship,
      'return' => 'value',
    ]);
    $dao = CRM_Core_DAO::executeQuery('SELECT DISTINCT financial_type_id FROM ' . $entityTable);
    while ($dao->fetch()) {
      if (!empty($dao->financial_type_id) && (
        $efaID = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($dao->financial_type_id, $oldAccountRelationship, 'civicrm_financial_type', 'id'))
      ) {
        civicrm_api3('EntityFinancialAccount', 'create', ['id' => $efaID, 'account_relationship' => $grantExpenseAccountID]);
      }
    }
  }

}
