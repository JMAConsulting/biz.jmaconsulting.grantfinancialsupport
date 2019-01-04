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
      GROUP BY eft.entity_id, fi.id, eb.batch_id
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

  public static function getFinancialTransactionsList() {
    CRM_Core_Error::debug_var('a', 'a');
    $sortMapper = array(
      0 => '',
      1 => '',
      2 => 'sort_name',
      3 => 'amount',
      4 => 'trxn_id',
      5 => 'transaction_date',
      6 => 'receive_date',
      7 => 'payment_method',
      8 => 'status',
      9 => 'name',
    );

    $sEcho = CRM_Utils_Type::escape($_REQUEST['sEcho'], 'Integer');
    $return = isset($_REQUEST['return']) ? CRM_Utils_Type::escape($_REQUEST['return'], 'Boolean') : FALSE;
    $offset = isset($_REQUEST['iDisplayStart']) ? CRM_Utils_Type::escape($_REQUEST['iDisplayStart'], 'Integer') : 0;
    $rowCount = isset($_REQUEST['iDisplayLength']) ? CRM_Utils_Type::escape($_REQUEST['iDisplayLength'], 'Integer') : 25;
    $sort = isset($_REQUEST['iSortCol_0']) ? CRM_Utils_Array::value(CRM_Utils_Type::escape($_REQUEST['iSortCol_0'], 'Integer'), $sortMapper) : NULL;
    $sortOrder = isset($_REQUEST['sSortDir_0']) ? CRM_Utils_Type::escape($_REQUEST['sSortDir_0'], 'String') : 'asc';
    $context = CRM_Utils_Request::retrieve('context', 'Alphanumeric');
    $entityID = isset($_REQUEST['entityID']) ? CRM_Utils_Type::escape($_REQUEST['entityID'], 'String') : NULL;
    $notPresent = isset($_REQUEST['notPresent']) ? CRM_Utils_Type::escape($_REQUEST['notPresent'], 'String') : NULL;
    $statusID = isset($_REQUEST['statusID']) ? CRM_Utils_Type::escape($_REQUEST['statusID'], 'String') : NULL;
    $search = isset($_REQUEST['search']) ? TRUE : FALSE;

    $params = $_POST;
    if ($sort && $sortOrder) {
      $params['sortBy'] = $sort . ' ' . $sortOrder;
    }

    $returnvalues = array(
      'civicrm_financial_trxn.payment_instrument_id as payment_method',
      'civicrm_contribution.contact_id as contact_id',
      'civicrm_contribution.id as contributionID',
      'contact_a.sort_name',
      'civicrm_financial_trxn.total_amount as amount',
      'civicrm_financial_trxn.trxn_id as trxn_id',
      'contact_a.contact_type',
      'contact_a.contact_sub_type',
      'civicrm_financial_trxn.trxn_date as transaction_date',
      'civicrm_contribution.receive_date as receive_date',
      'civicrm_financial_type.name',
      'civicrm_financial_trxn.currency as currency',
      'civicrm_financial_trxn.status_id as status',
      'civicrm_financial_trxn.check_number as check_number',
      'civicrm_financial_trxn.card_type_id',
      'civicrm_financial_trxn.pan_truncation',
    );

    $columnHeader = array(
      'contact_type' => '',
      'sort_name' => ts('Contact Name'),
      'amount' => ts('Amount'),
      'trxn_id' => ts('Trxn ID'),
      'transaction_date' => ts('Transaction Date'),
      'receive_date' => ts('Received'),
      'payment_method' => ts('Payment Method'),
      'status' => ts('Status'),
      'name' => ts('Type'),
    );

    if ($sort && $sortOrder) {
      $params['sortBy'] = $sort . ' ' . $sortOrder;
    }

    $params['page'] = ($offset / $rowCount) + 1;
    $params['rp'] = $rowCount;

    $params['context'] = $context;
    $params['offset'] = ($params['page'] - 1) * $params['rp'];
    $params['rowCount'] = $params['rp'];
    $params['sort'] = CRM_Utils_Array::value('sortBy', $params);
    $params['total'] = 0;

    // get batch list
    if (isset($notPresent)) {
      $financialItem = self::getBatchFinancialItems($entityID, $returnvalues, $notPresent, $params);
      if ($search) {
        $unassignedTransactions = self::getBatchFinancialItems($entityID, $returnvalues, $notPresent, $params, TRUE);
      }
      else {
        $unassignedTransactions = self::getBatchFinancialItems($entityID, $returnvalues, $notPresent, NULL, TRUE);
      }
      while ($unassignedTransactions->fetch()) {
        $unassignedTransactionsCount[] = $unassignedTransactions->id;
      }
      if (!empty($unassignedTransactionsCount)) {
        $params['total'] = count($unassignedTransactionsCount);
      }

    }
    else {
      $financialItem = self::getBatchFinancialItems($entityID, $returnvalues, NULL, $params);
      $assignedTransactions = self::getBatchFinancialItems($entityID, $returnvalues);
      while ($assignedTransactions->fetch()) {
        $assignedTransactionsCount[] = $assignedTransactions->id;
      }
      if (!empty($assignedTransactionsCount)) {
        $params['total'] = count($assignedTransactionsCount);
      }
    }
    $financialitems = array();
    if ($statusID) {
      $batchStatuses = CRM_Core_PseudoConstant::get('CRM_Batch_DAO_Batch', 'status_id', array('labelColumn' => 'name', 'condition' => " v.value={$statusID}"));
      $batchStatus = $batchStatuses[$statusID];
    }
    while ($financialItem->fetch()) {
      $row[$financialItem->id] = array();
      foreach ($columnHeader as $columnKey => $columnValue) {
        if ($financialItem->contact_sub_type && $columnKey == 'contact_type') {
          $row[$financialItem->id][$columnKey] = $financialItem->contact_sub_type;
          continue;
        }
        $row[$financialItem->id][$columnKey] = $financialItem->$columnKey;
        if ($columnKey == 'sort_name' && $financialItem->$columnKey && $financialItem->contact_id) {
          $url = CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid=" . $financialItem->contact_id);
          $row[$financialItem->id][$columnKey] = '<a href=' . $url . '>' . $financialItem->$columnKey . '</a>';
        }
        elseif ($columnKey == 'payment_method' && $financialItem->$columnKey) {
          $row[$financialItem->id][$columnKey] = CRM_Core_PseudoConstant::getLabel('CRM_Batch_BAO_Batch', 'payment_instrument_id', $financialItem->$columnKey);
          if ($row[$financialItem->id][$columnKey] == 'Check') {
            $checkNumber = $financialItem->check_number ? ' (' . $financialItem->check_number . ')' : '';
            $row[$financialItem->id][$columnKey] = $row[$financialItem->id][$columnKey] . $checkNumber;
          }
        }
        elseif ($columnKey == 'amount' && $financialItem->$columnKey) {
          $row[$financialItem->id][$columnKey] = CRM_Utils_Money::format($financialItem->$columnKey, $financialItem->currency);
        }
        elseif ($columnKey == 'transaction_date' && $financialItem->$columnKey) {
          $row[$financialItem->id][$columnKey] = CRM_Utils_Date::customFormat($financialItem->$columnKey);
        }
        elseif ($columnKey == 'receive_date' && $financialItem->$columnKey) {
          $row[$financialItem->id][$columnKey] = CRM_Utils_Date::customFormat($financialItem->$columnKey);
        }
        elseif ($columnKey == 'status' && $financialItem->$columnKey) {
          $row[$financialItem->id][$columnKey] = CRM_Core_PseudoConstant::getLabel('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $financialItem->$columnKey);
        }
      }
      if (isset($batchStatus) && in_array($batchStatus, array('Open', 'Reopened'))) {
        if (isset($notPresent)) {
          $js = "enableActions('x')";
          $row[$financialItem->id]['check'] = "<input type='checkbox' id='mark_x_" . $financialItem->id . "' name='mark_x_" . $financialItem->id . "' value='1' onclick={$js}></input>";
          $row[$financialItem->id]['action'] = CRM_Core_Action::formLink(
            CRM_Financial_Form_BatchTransaction::links(),
            NULL,
            array(
              'id' => $financialItem->id,
              'contid' => $financialItem->contributionID,
              'cid' => $financialItem->contact_id,
            ),
            ts('more'),
            FALSE,
            'financialItem.batch.row',
            'FinancialItem',
            $financialItem->id
          );
        }
        else {
          $js = "enableActions('y')";
          $row[$financialItem->id]['check'] = "<input type='checkbox' id='mark_y_" . $financialItem->id . "' name='mark_y_" . $financialItem->id . "' value='1' onclick={$js}></input>";
          $row[$financialItem->id]['action'] = CRM_Core_Action::formLink(
            CRM_Financial_Page_BatchTransaction::links(),
            NULL,
            array(
              'id' => $financialItem->id,
              'contid' => $financialItem->contributionID,
              'cid' => $financialItem->contact_id,
            ),
            ts('more'),
            FALSE,
            'financialItem.batch.row',
            'FinancialItem',
            $financialItem->id
          );
        }
      }
      else {
        $row[$financialItem->id]['check'] = NULL;
        $tempBAO = new CRM_Financial_Page_BatchTransaction();
        $links = $tempBAO->links();
        unset($links['remove']);
        $row[$financialItem->id]['action'] = CRM_Core_Action::formLink(
          $links,
          NULL,
          array(
            'id' => $financialItem->id,
            'contid' => $financialItem->contributionID,
            'cid' => $financialItem->contact_id,
          ),
          ts('more'),
          FALSE,
          'financialItem.batch.row',
          'FinancialItem',
          $financialItem->id
        );
      }
      if ($financialItem->contact_id) {
        $row[$financialItem->id]['contact_type'] = CRM_Contact_BAO_Contact_Utils::getImage(CRM_Utils_Array::value('contact_sub_type', $row[$financialItem->id]) ? $row[$financialItem->id]['contact_sub_type'] : CRM_Utils_Array::value('contact_type', $row[$financialItem->id]), FALSE, $financialItem->contact_id);
      }
      $financialitems = $row;
    }

    $iFilteredTotal = $iTotal = $params['total'];
    $selectorElements = array(
      'check',
      'contact_type',
      'sort_name',
      'amount',
      'trxn_id',
      'transaction_date',
      'receive_date',
      'payment_method',
      'status',
      'name',
      'action',
    );

    if ($return) {
      return CRM_Utils_JSON::encodeDataTableSelector($financialitems, $sEcho, $iTotal, $iFilteredTotal, $selectorElements);
    }
    CRM_Utils_System::setHttpHeader('Content-Type', 'application/json');
    echo CRM_Utils_JSON::encodeDataTableSelector($financialitems, $sEcho, $iTotal, $iFilteredTotal, $selectorElements);
    CRM_Utils_System::civiExit();
  }

  /**
   * Function to retrieve financial items assigned for a batch
   *
   * @param int $entityID
   * @param array $returnValues
   * @param null $notPresent
   * @param null $params
   * @return Object
   */
  public static function getBatchFinancialItems($entityID, $returnValues, $notPresent = NULL, $params = NULL, $getCount = FALSE) {
    if (!$getCount) {
      if (!empty($params['rowCount']) &&
        $params['rowCount'] > 0
      ) {
        $limit = " LIMIT {$params['offset']}, {$params['rowCount']} ";
      }
    }
    // action is taken depending upon the mode
    $select = 'civicrm_financial_trxn.id ';
    if (!empty( $returnValues)) {
      $select .= " , ".implode(' , ', $returnValues);
    }

    $orderBy = " ORDER BY civicrm_financial_trxn.id";
    if (!empty($params['sort'])) {
      $orderBy = ' ORDER BY ' . CRM_Utils_Type::escape($params['sort'], 'String');
    }

    $from = "civicrm_financial_trxn
  LEFT JOIN civicrm_entity_financial_trxn ON civicrm_entity_financial_trxn.financial_trxn_id = civicrm_financial_trxn.id
  LEFT JOIN civicrm_entity_batch ON civicrm_entity_batch.entity_id = civicrm_financial_trxn.id
  LEFT OUTER JOIN civicrm_contribution ON civicrm_contribution.id = civicrm_entity_financial_trxn.entity_id AND civicrm_entity_financial_trxn.entity_table = 'civicrm_contribution'
  LEFT OUTER JOIN civicrm_grant ON civicrm_grant.id = civicrm_entity_financial_trxn.entity_id AND civicrm_entity_financial_trxn.entity_table = 'civicrm_grant'
  LEFT JOIN civicrm_financial_type ON civicrm_financial_type.id = IFNULL(civicrm_contribution.financial_type_id, civicrm_grant.financial_type_id)
  LEFT JOIN civicrm_contact contact_a ON contact_a.id = IFNULL(civicrm_contribution.contact_id, civicrm_grant.contact_id)
  LEFT JOIN civicrm_contribution_soft ON civicrm_contribution_soft.contribution_id = civicrm_contribution.id
  ";

    $searchFields =
      array(
        'sort_name',
        'financial_type_id',
        'contribution_page_id',
        'contribution_payment_instrument_id',
        'contribution_transaction_id',
        'contribution_source',
        'contribution_currency_type',
        'contribution_pay_later',
        'contribution_recurring',
        'contribution_test',
        'contribution_thankyou_date_is_not_null',
        'contribution_receipt_date_is_not_null',
        'contribution_pcp_made_through_id',
        'contribution_pcp_display_in_roll',
        'contribution_date_relative',
        'contribution_amount_low',
        'contribution_amount_high',
        'contribution_in_honor_of',
        'contact_tags',
        'group',
        'contribution_date_relative',
        'contribution_date_high',
        'contribution_date_low',
        'contribution_check_number',
        'contribution_status_id',
      );
    $values = array();
    foreach ($searchFields as $field) {
      if (isset($params[$field])) {
        $values[$field] = $params[$field];
        if ($field == 'sort_name') {
          $from .= " LEFT JOIN civicrm_contact contact_b ON contact_b.id = civicrm_contribution.contact_id
          LEFT JOIN civicrm_email ON contact_b.id = civicrm_email.contact_id";
        }
        if ($field == 'contribution_in_honor_of') {
          $from .= " LEFT JOIN civicrm_contact contact_b ON contact_b.id = civicrm_contribution.contact_id";
        }
        if ($field == 'contact_tags') {
          $from .= " LEFT JOIN civicrm_entity_tag `civicrm_entity_tag-{$params[$field]}` ON `civicrm_entity_tag-{$params[$field]}`.entity_id = contact_a.id";
        }
        if ($field == 'group') {
          $from .= " LEFT JOIN civicrm_group_contact `civicrm_group_contact-{$params[$field]}` ON contact_a.id = `civicrm_group_contact-{$params[$field]}`.contact_id ";
        }
        if ($field == 'contribution_date_relative') {
          $relativeDate = explode('.', $params[$field]);
          $date = CRM_Utils_Date::relativeToAbsolute($relativeDate[0], $relativeDate[1]);
          $values['contribution_date_low'] = $date['from'];
          $values['contribution_date_high'] = $date['to'];
        }
        $searchParams = CRM_Contact_BAO_Query::convertFormValues(
          $values,
          0,
          FALSE,
          NULL,
          [
            'financial_type_id',
            'contribution_soft_credit_type_id',
            'contribution_status_id',
            'contribution_page_id',
            'financial_trxn_card_type_id',
            'contribution_payment_instrument_id',
          ]
       );
        $query = new CRM_Contact_BAO_Query($searchParams,
          CRM_Contribute_BAO_Query::defaultReturnProperties(CRM_Contact_BAO_Query::MODE_CONTRIBUTE,
            FALSE
          ),NULL, FALSE, FALSE,CRM_Contact_BAO_Query::MODE_CONTRIBUTE
        );
        if ($field == 'contribution_date_high' || $field == 'contribution_date_low') {
          $query->dateQueryBuilder($params[$field], 'civicrm_contribution', 'contribution_date', 'receive_date', 'Contribution Date');
        }
      }
    }
    if (!empty($query->_where[0])) {
      $where = implode(' AND ', $query->_where[0]) .
        " AND civicrm_entity_batch.batch_id IS NULL
          AND (civicrm_grant.id IS NOT NULL OR civicrm_contribution.id IS NOT NULL)";
      $searchValue = TRUE;
    }
    else {
      $searchValue = FALSE;
    }

    if (!$searchValue) {
      if (!$notPresent) {
        $where =  " ( civicrm_entity_batch.batch_id = {$entityID}
        AND civicrm_entity_batch.entity_table = 'civicrm_financial_trxn'
        AND (civicrm_grant.id IS NOT NULL OR civicrm_contribution.id IS NOT NULL) )";
      }
      else {
        $where = " ( civicrm_entity_batch.batch_id IS NULL
        AND (civicrm_grant.id IS NOT NULL OR civicrm_contribution.id IS NOT NULL) )";
      }
    }

    $sql = "
  SELECT {$select}
  FROM   {$from}
  WHERE  {$where}
       {$orderBy}
  ";

    if (isset($limit)) {
      $sql .= "{$limit}";
    }

    $result = CRM_Core_DAO::executeQuery($sql);
    return $result;
  }

}
