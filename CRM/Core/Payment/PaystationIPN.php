<?php

/**
 * Paystation Functionality Copyright (C) 2010 Elliot Pahl, Catalyst IT Limited.
 *
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 *   GNU Affero General Public License, version 3
 */

class CRM_Core_Payment_PaystationIPN extends CRM_Core_Payment_BaseIPN {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  protected static $_mode = null;
  static function retrieve($name, $type, $object, $abort = true) {
    $value = CRM_Utils_Array::value($name, $object);
    if ($abort && $value === null) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name");
      echo "Failure: Missing Parameter - " . $name . "<p>";
      exit();
    }

    if ($value) {
      if (! CRM_Utils_Type::validate($value, $type)) {
        CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
        echo "Failure: Invalid Parameter<p>";
        exit();
      }
    }

    return $value;
  }

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode = NULL, &$paymentProcessor = NULL) {
    parent::__construct();

    if (!is_null($mode)) {
      $this->_mode = $mode;
    }
    if (!is_null($paymentProcessor)) {
      $this->_paymentProcessor = $paymentProcessor;
    }
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   */
  static function &singleton($mode, $component, &$paymentProcessor) {
    if (self::$_singleton === null) {
      self::$_singleton = new CRM_Core_Payment_PaystationIPN($mode, $paymentProcessor);
    }
    return self::$_singleton;
  }

  /**
   * The function gets called when a new order takes place.
   *
   * @param array  $privateData  contains the CiviCRM related data
   * @param string $component    the CiviCRM component
   * @param array  $merchantData contains the Merchant related data
   *
   * @return void
   *
   */
  function newOrderNotify($privateData, $component, $merchantData) {
    $ids = $input = $params = array();

    $input['component'] = strtolower($component);

    $ids['contact'] = self::retrieve('contactID', 'Integer', $privateData, true);
    $ids['contribution'] = self::retrieve('contributionID', 'Integer', $privateData, true);

    if ($input['component'] == "event") {
      $ids['event'] = self::retrieve('eventID', 'Integer', $privateData, true);
      $ids['participant'] = self::retrieve('participantID', 'Integer', $privateData, true);
      $ids['membership'] = null;
    }
    else {
      $ids['membership'] = self::retrieve('membershipID', 'Integer', $privateData, false);
    }
    $ids['contributionRecur'] = $ids['contributionPage'] = null;

    if (! $this->validateData($input, $ids, $objects)) {
      return false;
    }

    // make sure the invoice is valid and matches what we have in the
    // contribution record
    $input['invoice'] = $privateData['invoiceID'];
    $input['newInvoice'] = $merchantData['PaystationTransactionID'];
    $contribution = & $objects['contribution'];
    $input['trxn_id'] = $merchantData['PaystationTransactionID'];

    if ($contribution->invoice_id != $input['invoice']) {
      CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
      echo "Failure: Invoice values dont match between database and IPN request<p>";
      return;
    }

    // let's replace invoice-id with Payment Processor -number because
    // thats what is common and unique in subsequent calls or
    // notifications sent by processor
    $contribution->invoice_id = $input['newInvoice'];

    $input['amount'] = $merchantData['PurchaseAmount'];

    if ($contribution->total_amount != $input['amount']) {
      CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
      echo "Failure: Amount values dont match between database and IPN request. " . $contribution->total_amount . "/" . $input['amount'] . "<p>";
      return;
    }

    require_once 'CRM/Core/Transaction.php';
    $transaction = new CRM_Core_Transaction();

    $this->validateData($input, $ids, $objects);

    /**
     * #=====================================================
     * #ec response code lookup
     * #=====================================================
     * #ec = 0 : No error - transaction succesful
     * #ec = 1 : Unknown error
     * #ec = 2 : Bank declined transaction
     * #ec = 3 : No reply from bank
     * #ec = 4 : Expired card
     * #ec = 5 : Insufficient funds
     * #ec = 6 : Error communicating with bank
     * #ec = 7 : Payment server system error
     * #ec = 8 : Transaction type not supported
     * #ec = 9 : Transaction failed
     * #ec = 10 : Purchase amount less or greater than merchant values
     * #ec = 11 : Paystation couldnt create order based on inputs
     * #ec = 12 : Paystation couldnt find merchant based on merchant ID
     * #ec = 13 : Transaction already in progress
     * #note: These relate to qsiResponseCode from client
     * #=====================================================
     */
    switch ($merchantData['PaystationErrorCode']) {
      // success
      case 0:
        break;
        // unhandled
      case 1:
        return $this->unhandled($objects, $transaction);
        break;
        // failed
      case 2:
      case 3:
      case 4:
      case 5:
      case 6:
      case 7:
      case 8:
      case 9:
      case 10:
      case 11:
      case 12:
        return $this->failed($objects, $transaction);
        break;
        /* pending?
           case 13:
           return $this->pending( $objects, $transaction );
           break;
        //*/
    }

    // check if contribution is already completed, if so we ignore
    // this ipn
    if ($contribution->contribution_status_id == 1) {
      $transaction->commit();
      CRM_Core_Error::debug_log_message("returning since contribution has already been handled");
      echo "Success: Contribution has already been handled<p>";
      return true;
    }
    else {
      /**
       * Since trxn_id hasn't got any use here, lets make use of it by
       * passing the eventID/membershipTypeID to next level.  And
       * change trxn_id to the payment processor reference before
       * finishing db update
       */
      if ($ids['event']) {
        $contribution->trxn_id = $ids['event'] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['participant'];
      }
      else {
        $contribution->trxn_id = $ids['membership'];
      }
    }
    $this->completeTransaction($input, $ids, $objects, $transaction);
    return true;
  }

  /**
   * The function returns the component(Event/Contribute..)and whether
   * it is Test or not
   *
   * @param array   $privateData    contains the name-value pairs of transaction related data
   * @param int     $orderNo        <order-total> send by google
   *
   * @return array context of this call (test, component, payment processor id)
   * @static
   */
  static function getContext($privateData, $orderNo) {
    $component = null;
    $isTest = null;

    $contributionID = $privateData['contributionID'];
    $contribution = & new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionID;

    if (! $contribution->find(true)) {
      CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
      echo "Failure: Could not find contribution record for $contributionID<p>";
      exit();
    }

    if (stristr($contribution->source, 'Online Contribution')) {
      $component = 'contribute';
    }
    else if (stristr($contribution->source, 'Online Event Registration')) {
      $component = 'event';
    }
    $isTest = $contribution->is_test;

    $duplicateTransaction = 0;
    if ($contribution->contribution_status_id == 1) {
      // contribution already handled. (some processors do two
      // notifications so this could be valid)
      $duplicateTransaction = 1;
    }

    if ($component == 'contribute') {
      if (! $contribution->contribution_page_id) {
        CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
        echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
        exit();
      }
    }
    else {

      $eventID = $privateData['eventID'];

      if (! $eventID) {
        CRM_Core_Error::debug_log_message("Could not find event ID");
        echo "Failure: Could not find eventID<p>";
        exit();
      }

      // we are in event mode, make sure event exists and is valid
      require_once 'CRM/Event/DAO/Event.php';
      $event = & new CRM_Event_DAO_Event();
      $event->id = $eventID;
      if (! $event->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find event: $eventID");
        echo "Failure: Could not find event: $eventID<p>";
        exit();
      }
    }
    $paymentProcessorID = CRM_Core_DAO::getFieldValue(
      'CRM_Financial_DAO_PaymentProcessor',
      'Paystation',
      'id',
      'payment_processor_type'
    );
    if (! $paymentProcessorID) {
      CRM_Core_Error::debug_log_message("Could not find payment processor for contribution record: $contributionID");
      echo "Failure: Could not find payment processor for contribution record: $contributionID<p>";
      exit();
    }

    return array(
      $isTest,
      $component,
      $paymentProcessorID,
      $duplicateTransaction
    );
  }

  /**
   * This method is handles the response that will be invoked by the
   * notification or request sent by the payment processor.
   *
   * hex string from paymentexpress is passed to this function as hex
   * string.
   */
  function main($rawPostData, $ps_url, $ps_api, $ps_user, $ps_key) {
    $config = CRM_Core_Config::singleton();
    define('RESPONSE_HANDLER_LOG_FILE', $config->configAndLogDir . 'CiviCRM.Paystation.log');
    $transactionID = isset($rawPostData['ti']) ? $rawPostData['ti'] : '';
    $errorCode = isset($rawPostData['ec']) ? $rawPostData['ec'] : '';
    $errorMessage = isset($rawPostData['em']) ? $rawPostData['em'] : '';
    //$merchantSession = isset($rawPostData['ms']) ? $rawPostData['ms'] : '';
    $amount = isset($rawPostData['am']) ? ($rawPostData['am']) / 100 : 0;
    $privateData = isset($rawPostData['data']) ? $rawPostData['data'] : '';
    $component = isset($rawPostData['component']) ? $rawPostData['component'] : '';
    $qfKey = isset($rawPostData['qfKey']) ? $rawPostData['qfKey'] : '';

    // Quick lookup
    // Setup the log file
    if (! $message_log = fopen(RESPONSE_HANDLER_LOG_FILE, "a")) {
      error_func("Cannot open " . RESPONSE_HANDLER_LOG_FILE . " file.\n", 0);
      exit(1);
    }

    $url = $ps_api; // http://www.paystation.co.nz/lookup/quick/
    $qlParams = '?pi=' . $ps_user;
    $qlParams .= isset($rawPostData['ti']) ? '&ti=' . $rawPostData['ti'] : '';
    $url .= $qlParams;
    fwrite($message_log, sprintf("\n\r%s:- %s\n", date("D M j G:i:s T Y"), " LINE " . __LINE__ . ' Quick Lookup: ' . $url));

    $success = false;

    // Perform the quick lookup to get data from Paystation
    $utils = new CRM_Core_Payment_PaystationUtils();
    if ($response = $utils->quickLookup($url, $message_log)) {
      //CRM_Core_Error::debug_var('response', $response);
      fwrite($message_log, sprintf("\n\r%s:- %s\n", date("D M j G:i:s T Y"), " LINE " . __LINE__ . ' ' . $response));

      $xml = simplexml_load_string($response);
      if (isset($xml)) {
        $status = (array) $xml->LookupStatus;
        //CRM_Core_Error::debug_var('status', $status);


        if ($status['LookupCode'] == '00') {
          $responseData = (array) $xml->LookupResponse;
          CRM_Core_Error::debug_var('responseData', $responseData);

          $PaystationErrorCode = isset($responseData['PaystationErrorCode']) ? $responseData['PaystationErrorCode'] : '';
          $PaystationTransactionID = isset($responseData['PaystationTransactionID']) ? $responseData['PaystationTransactionID'] : '';
          $PurchaseAmount = isset($responseData['PurchaseAmount']) ? $responseData['PurchaseAmount'] / 100 : 0; // in cents
          /*
            $AcquirerName            = isset($responseData['AcquirerName']) ? $responseData['AcquirerName'] : '';
            $AcquirerMerchantID      = isset($responseData['AcquirerMerchantID']) ? $responseData['AcquirerMerchantID'] : '';
            $TransactionTime         = isset($responseData['TransactionTime']) ? $responseData['TransactionTime'] : '';
            $ReturnReceiptNumber     = isset($responseData['ReturnReceiptNumber']) ? $responseData['ReturnReceiptNumber'] : '';
            $AcquirerResponseCode    = isset($responseData['AcquirerResponseCode']) ? $responseData['AcquirerResponseCode'] : '';
            $MerchantSession         = isset($responseData['MerchantSession']) ? $responseData['MerchantSession'] : '';
          //*/

          $errorCode = $PaystationErrorCode;
          if ($PaystationErrorCode == 0) {
            $success = true;
          }

          $privateData = $privateData ? self::stringToArray($privateData) : '';
          // Private Data consists of : a=contactID,b=contributionID,c=contributionTypeID,d=invoiceID,e=membershipID,f=participantID,g=eventID,h=paystationID
          $privateData['contactID'] = $privateData['a'];
          $privateData['contributionID'] = $privateData['b'];
          $privateData['contributionTypeID'] = $privateData['c'];
          $privateData['invoiceID'] = $privateData['d'];
          $privateData['paystationID'] = $privateData['h'];

          if ($component == "event") {
            $privateData['participantID'] = $privateData['f'];
            $privateData['eventID'] = $privateData['g'];
          }
          else if ($component == "contribute") {
            $privateData["membershipID"] = $privateData['e'];
          }

          $merchantData = array();
          $merchantData['PaystationErrorCode'] = $PaystationErrorCode;
          $merchantData['PurchaseAmount'] = $PurchaseAmount;
          $merchantData['PaystationTransactionID'] = $PaystationTransactionID;

          list ($mode, $component, $paymentProcessorID, $duplicateTransaction) = self::getContext($privateData, $PaystationTransactionID);
          $mode = $mode ? 'test' : 'live';

          CRM_Core_Error::debug_var('component', $component);
          CRM_Core_Error::debug_var('duplicateTransaction', $duplicateTransaction);

          $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($paymentProcessorID, $mode);

          $ipn = self::singleton($mode, $component, $paymentProcessor);

          if ($duplicateTransaction == 0) {
            $ipn->newOrderNotify($privateData, $component, $merchantData);
          }

          // Check status and take appropriate action
          if ($component == "event") {
            $baseURL = 'civicrm/event/register';
            $query = $success ? "_qf_ThankYou_display=1&qfKey={$qfKey}" : "_qf_Register_display=1&cancel=1&qfKey={$qfKey}";
          }
          else if ($component == "contribute") {
            $baseURL = 'civicrm/contribute/transact';
            $query = $success ? "_qf_ThankYou_display=1&qfKey={$qfKey}" : "_qf_Main_display=1&cancel=1&qfKey={$qfKey}";
          }
          else {
            // Invalid component
            CRM_Core_Error::fatal(ts('Invalid component "' . $component . '" selected.'));
            exit();
          }
          // path, query, absolute, fragment, htmlize
          $finalURL = CRM_Utils_System::url($baseURL, $query, false, null, false);

          CRM_Utils_System::redirect($finalURL);
        }
      }
    }
    else {
      // calling Paystation failed
      CRM_Core_Error::debug_var('response', $response);
      CRM_Core_Error::fatal(ts('Unable to establish connection to the payment gateway to verify transaction response.'));
      exit();
    }
  }

  /**
   * Converts the comma separated name-value pairs in <TxnData2> to an
   * array of values.
   */
  static function stringToArray($str) {
    $str = urldecode($str);
    $vars = $labels = array();
    $labels = explode(',', $str);
    foreach ($labels as $label) {
      $terms = explode('=', $label);
      $vars[$terms[0]] = $terms[1];
    }
    return $vars;
  }
}
