<?php

/*
 * Paystation Functionality Copyright (C) 2010 Elliot Pahl, Catalyst IT Limited
 *
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 *   GNU Affero General Public License, version 3
 */

class CRM_Core_Payment_Paystation extends CRM_Core_Payment {
  const CHARSET = 'iso-8859-1';
  protected $_mode = null;
  protected $_params = array();

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   * @param array $paymentProcessor
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Paystation');
    $this->_processorId = $paymentProcessor['id'];
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Paystation($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * @TODO Please document this function.
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();

    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Paystation ID is not set in the Administer CiviCRM &raquo; Payment Processor.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Gateway ID is not set in the Administer CiviCRM &raquo; Payment Processor.');
    }

    if (! empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return null;
    }
  }

  /**
   * @TODO Please document this function.
   */
  function setExpressCheckOut(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * @TODO Please document this function.
   */
  function getExpressCheckoutDetails($token) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * @TODO Please document this function.
   */
  function doExpressCheckout(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * @TODO Please document this function.
   */
  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Main transaction function
   *
   * @param array $params  name value pair of contribution data
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout(&$params, $component) {
    $component = strtolower($component);
    $cancelURL = $this->getCancelUrlForComponent($params, $component);
    $url = CRM_Utils_System::url("civicrm/payment/ipn", "processor_id={$params['payment_processor_id']}",false, null, false);
    $config = CRM_Core_Config::singleton();

    /**
     * Build the private data string to pass to Paystation, which they
     * will give back to us with the transaction result.  We are
     * building this as a comma-separated list so as to avoid long
     * URLs.
     *
     * Parameters passed: a=contactID, b=contributionID,c=contributionTypeID,d=invoiceID,e=membershipID,f=participantID,g=eventID,h=paystationID
     */
    $privateData = "a={$params['contactID']},b={$params['contributionID']},d={$params['invoiceID']},h={$this->_paymentProcessor['user_name']}";

    if ($component == 'event') {
      $privateData .= ",f={$params['participantID']},g={$params['eventID']}";
      $merchantRef = "Event Registration";
    }
    else if ($component == 'contribute') {
      $merchantRef = "Contribution";
      $membershipID = CRM_Utils_Array::value('membershipID', $params);
      if ($membershipID) {
        $privateData .= ",e=$membershipID";
      }
    }

    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $privateData);

    // Paystation parameters
    $paystationURL = $this->_paymentProcessor['url_site'];
    $site = $config->userFrameworkResourceURL;

    // "paystation&pstn_pi=".$pstn_pi."&pstn_gi=".$pstn_gi."&pstn_ms=".$merchantSession."&pstn_am=".$amount."&pstn_mr=".$pstn_mr."&pstn_nr=t";
    $psParams = array(
      'pstn_pi' => $this->_paymentProcessor['user_name'],  // Paystation ID
      'pstn_gi' => $this->_paymentProcessor['password'],  // Gateway ID
      'pstn_ms' => time() . '_' . $params['qfKey'],  // Merchant Session ** unique for each financial transaction request
      'pstn_am' => str_replace(",", "", number_format($params['amount'], 2)),  // Amount
      'pstn_af' => 'dollars.cents',  // Amount Format (optional): 'dollars.cents' or 'cents'
      'pstn_mr' => $merchantRef,  // Merchant Reference (optional)
      'pstn_nr' => 't',  // Undocumented
      'data' => $privateData,  // Data to be passed back to us
      'component' => $component,
      'qfKey' => $params['qfKey']
    );

    if ($this->_mode == 'test') {
      $psParams['pstn_tm'] = 't'; // Test mode
    }

    $utils = new CRM_Core_Payment_PaystationUtils();
    $paystationParams = 'paystation&' . $utils->paystation_query_string_encode($psParams);

    CRM_Core_Error::debug_log_message('Paystation Params: ' . $paystationParams);
    if ($initiationResult = $utils->directTransaction($paystationURL, $paystationParams)) {
      $xml = simplexml_load_string($initiationResult);
      if (isset($xml)) {
        $result = (array) $xml;

        if (! empty($result['PaystationTransactionID'])) {
          // the request was validated, so we'll get the URL and redirect to it
          if (! empty($result['DigitalOrder'])) {
            $uri = $result['DigitalOrder'];
            CRM_Core_Error::debug_log_message('Paystation Redirect URI: ' . $uri);
            CRM_Utils_System::redirect($uri);
          }
          else {
            // redisplay confirmation page
            CRM_Core_Error::debug_log_message('Paystation XML: DigitalOrder element was empty.');
            CRM_Utils_System::redirect($cancelURL);
          }
        }
        else {
          // redisplay confirmation page
          CRM_Core_Error::debug_log_message('Paystation XML: PaystationTransactionID element was empty.');
          CRM_Utils_System::redirect($cancelURL);
        }
      }
      else {
        // redisplay confirmation page
        CRM_Core_Error::debug_log_message('XML from paystation couldn\'t be loaded by simplexml.');
        CRM_Utils_System::redirect($cancelURL);
      }
    }
    else {
      // calling Paystation failed
      CRM_Core_Error::fatal(ts('Unable to establish connection to the payment gateway.'));
    }
  }

  /**
   * Handle return response from payment processor
   */
  function handlePaymentNotification(){
    if ($_GET['processor_id']) {
      $params = array(
        'id' => $_GET['processor_id'],
      );
    }
    elseif ($_GET['processor_name']) {
      $params = array(
        'payment_processor_type_id' => civicrm_api3(
          'payment_processor_type',
          'getvalue',
          array(
            'name' => $_GET['processor_name'],
            'return' => 'id',
          )
        ),
        'is_test' => (CRM_Utils_Array::value('mode', $_GET) == 'test') ?  1 : 0,
      );
    }
    try {
      $paymentProcessor = civicrm_api3('payment_processor', 'getsingle', $params);
    }
    catch (Exception $e) {
      CRM_Core_Error::fatal('Payment processor not found for params: ' . var_export($params,1));
    }

    $paystationIPN = new CRM_Core_Payment_PaystationIPN();
    $httpRequest = $_GET;
    if(empty($httpRequest['data'] )){
      $postXml = (array) simplexml_load_string(file_get_contents("php://input"));
      $userData =  (array) $postXml['UserAdditionalVars'];
      $httpRequest = array_merge($postXml, (array) $postXml['UserAdditionalVars']);
    }
    $data = isset($httpRequest['data']) ? $paystationIPN->stringToArray($httpRequest['data']) : array();

    if (!empty($data)) {
      $psUrl = $paymentProcessor['url_site'];
      $psApi = $paymentProcessor['url_api'];
      $psUser = $paymentProcessor['user_name'];
      $psKey = $paymentProcessor['password'];

      $rawPostData = array(
        'ti' => $httpRequest['ti'],
        'ec' => $httpRequest['ec'],
        'em' => $httpRequest['em'],
        'ms' => $httpRequest['ms'],
        'am' => $httpRequest['am'], // Amount in *cents*
        'data' => $httpRequest['data'],
        'component' => $httpRequest['component'],
        'qfKey' => $httpRequest['qfKey']
      );
    }
    else{
      CRM_Core_Error::debug_log_message( "Failed to decode return IPN string" );
    }

    $paystationIPN->main($rawPostData, $psUrl, $psApi, $psUser, $psKey);

    // PaystationIPN::main() exits, but if for any reason we come back
    // here, we'll file a written complaint to our seniors.
    CRM_Core_Error::debug_log_message( "It should not be possible to reach this line" );
  }

  /**
   * Get URL which the browser should be returned to if they cancel or
   * are unsuccessful
   *
   * @params array
   * @component string $component function is called from
   * @return string $cancelURL Fully qualified return URL
   * @todo Ideally this would be in the parent payment class
   */
  function getCancelUrlForComponent($params, $component){
    $component = strtolower( $component );
    if ( $component != 'contribute' && $component != 'event' ) {
      CRM_Core_Error::fatal( ts( 'Component is invalid' ) );
    }
    if ( $component == 'event') {
      $cancelURL = CRM_Utils_System::url( 'civicrm/event/register',
                   "_qf_Confirm_display=true&qfKey={$params['qfKey']}",
                   false, null, false );
    } else if ( $component == 'contribute' ) {
      $cancelURL = CRM_Utils_System::url( 'civicrm/contribute/transact',
                   "_qf_Confirm_display=true&qfKey={$params['qfKey']}",
                   false, null, false );
    }
    return $cancelURL;
  }
}
