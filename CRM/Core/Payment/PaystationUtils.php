<?php

/**
 * Paystation Functionality Copyright (C) 2010 Elliot Pahl, Catalyst IT Limited
 *
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 *   GNU Affero General Public License, version 3
 */
class CRM_Core_Payment_PaystationUtils {

  /**
   * @param string $url
   * @param array $params
   * @return mixed
   */
  function directTransaction($url, $params) {
    $defined_vars = get_defined_vars();
    $http_user_agent = isset($defined_vars['HTTP_USER_AGENT']) ? $defined_vars['HTTP_USER_AGENT'] : '';

    //use curl to get response
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_USERAGENT, $http_user_agent);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
  }

  /**
   *
   * @param string $url
   * @param string $message_log
   * @return mixed
   */
  function quickLookup($url, &$message_log) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_HEADER, 0);

    fwrite($message_log, sprintf("\n\r%s:- %s\n", date("D M j G:i:s T Y"), $curl));

    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
  }

  /**
   *
   * @param string $query
   * @param array $exclude
   * @param string $parent
   * @return string
   */
  function paystation_query_string_encode($query, $exclude = array(), $parent = '') {
    $params = array();

    foreach ($query as $key => $value) {
      $key = urlencode($key);
      if ($parent) {
        $key = $parent . '[' . $key . ']';
      }

      if (in_array($key, $exclude)) {
        continue;
      }

      if (is_array($value)) {
        $params[] = paystation_query_string_encode($value, $exclude, $key);
      }
      else {
        $params[] = $key . '=' . urlencode($value);
      }
    }

    return implode('&', $params);
  }
}
