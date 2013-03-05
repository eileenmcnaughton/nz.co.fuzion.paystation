<?php

/*
 * Paystation Functionality Copyright (C) 2010 Elliot Pahl, Catalyst IT Limited
 * @license http://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License, version 3
 */

session_start();

require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';

$config = & CRM_Core_Config::singleton();

/*
 * Get the password from the Payment Processor's table based on the Paystation User ID being
 * passed back from the server
 */
$query = "
SELECT  url_site, url_api, password, user_name, signature
FROM    civicrm_payment_processor
WHERE   payment_processor_type = 'Paystation'
AND     user_name = %1
";

require_once 'CRM/Core/Payment/PaystationIPN.php';

//CRM_Core_Error::debug_var('_GET', $_GET);
$data = isset($_GET['data']) ? CRM_Core_Payment_PaystationIPN::stringToArray($_GET['data']) : array();
//CRM_Core_Error::debug_var('data', $data);


if (isset($data['h'])) {
  $data['paystationID'] = $data['h'];
  $params = array(
    1 => array(
      $data['paystationID'],
      'String'
    )
  );
  $psSettings = & CRM_Core_DAO::executeQuery($query, $params);

  while ($psSettings->fetch()) {
    $psUrl = $psSettings->url_site;
    $psApi = $psSettings->url_api;
    $psUser = $psSettings->user_name;
    $psKey = $psSettings->password;
  }

  $rawPostData = array(
    'ti' => $_GET['ti'],
    'ec' => $_GET['ec'],
    'em' => $_GET['em'],
    'ms' => $_GET['ms'],
    'am' => $_GET['am'],  // Amount in *cents*
    'data' => $_GET['data'],
    'component' => $_GET['component'],
    'qfKey' => $_GET['qfKey']
  );
  //CRM_Core_Error::debug_var('rawPostData', $rawPostData);


  CRM_Core_Payment_PaystationIPN::main($rawPostData, $psUrl, $psApi, $psUser, $psKey);
}
else {
  CRM_Core_Error::fatal(ts("The payment gateway hasn't returned with enough information to continue.  paystationID is missing."));
}

