<?php

/*
 * Paystation Functionality Copyright (C) 2010 Elliot Pahl, Catalyst IT Limited
 * @license http://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License, version 3
 */

require_once 'CRM/Core/Payment.php';

class CRM_Core_Payment_Paystation extends CRM_Core_Payment {
    const
        CHARSET = 'iso-8859-1';
    static protected $_mode = null;

    static protected $_params = array();

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = null;

    /**
     * Constructor
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return void
     */
    function __construct( $mode, &$paymentProcessor ) {

        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Paystation');
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
    static function &singleton( $mode, &$paymentProcessor ) {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null ) {
            self::$_singleton[$processorName] = new CRM_Core_Payment_Paystation( $mode, $paymentProcessor );
        }
        return self::$_singleton[$processorName];
    }

    function checkConfig( ) {
        $config = CRM_Core_Config::singleton( );

        $error = array( );

        if ( empty( $this->_paymentProcessor['user_name'] ) ) {
            $error[] = ts( 'Paystation ID is not set in the Administer CiviCRM &raquo; Payment Processor.' );
        }

        if ( empty( $this->_paymentProcessor['password'] ) ) {
            $error[] = ts( 'Gateway ID is not set in the Administer CiviCRM &raquo; Payment Processor.' );
        }

        if ( ! empty( $error ) ) {
            return implode( '<p>', $error );
        } else {
            return null;
        }
    }

    function setExpressCheckOut( &$params ) {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

    function getExpressCheckoutDetails( $token ) {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

    function doExpressCheckout( &$params ) {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

    function doDirectPayment( &$params ) {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
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
    function doTransferCheckout( &$params, $component ) {
        $component = strtolower( $component );
        $config    = CRM_Core_Config::singleton( );
        if ( $component != 'contribute' && $component != 'event' ) {
            CRM_Core_Error::fatal( ts( 'Component is invalid' ) );
        }

        $url = $config->userFrameworkResourceURL . "extern/psIPN.php";

        if ( $component == 'event') {
            $cancelURL = CRM_Utils_System::url(
                'civicrm/event/register',
                "_qf_Confirm_display=true&qfKey={$params['qfKey']}",
                false, null, false
            );
        } else if ( $component == 'contribute' ) {
            $cancelURL = CRM_Utils_System::url(
                'civicrm/contribute/transact',
                "_qf_Confirm_display=true&qfKey={$params['qfKey']}",
                false, null, false
            );
        }

        /*
         * Build the private data string to pass to Paystation, which they will give back to us with the
         * transaction result.  We are building this as a comma-separated list so as to avoid long URLs.
         * Parameters passed: a=contactID, b=contributionID,c=contributionTypeID,d=invoiceID,e=membershipID,f=participantID,g=eventID,h=paystationID
         */
        $privateData = "a={$params['contactID']},b={$params['contributionID']},c={$params['contributionTypeID']},d={$params['invoiceID']},h={$this->_paymentProcessor['user_name']}";
        //$privateData .= ',h='.$this->_paymentProcessor['user_name'];

        if ( $component == 'event') {
            $privateData .= ",f={$params['participantID']},g={$params['eventID']}";
            $merchantRef = "Event Registration";
        }
        else if ( $component == 'contribute' ) {
            $merchantRef = "Contribution";
            $membershipID = CRM_Utils_Array::value( 'membershipID', $params );
            if ( $membershipID ) {
                $privateData .= ",e=$membershipID";
            }
        }

        // Allow further manipulation of params via custom hooks
        CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $privateData );

        require_once 'CRM/Core/Payment/PaystationUtils.php';
        require_once 'CRM/Core/Error.php';

        // Paystation parameters
        $paystationURL = $this->_paymentProcessor['url_site'];
        $site = $config->userFrameworkResourceURL;

        //"paystation&pstn_pi=".$pstn_pi."&pstn_gi=".$pstn_gi."&pstn_ms=".$merchantSession."&pstn_am=".$amount."&pstn_mr=".$pstn_mr."&pstn_nr=t";
        $psParams = array(
            'pstn_pi'   => $this->_paymentProcessor['user_name'],                   // Paystation ID
            'pstn_gi'   => $this->_paymentProcessor['password'],                    // Gateway ID
            'pstn_ms'   => time() . '_' . $params['qfKey'],                         // Merchant Session ** unique for each financial transaction request
            'pstn_am'   => str_replace(",","", number_format($params['amount'],2)), // Amount
            'pstn_af'   => 'dollars.cents',                                         // Amount Format (optional): 'dollars.cents' or 'cents'
            'pstn_mr'   => $merchantRef,                                            // Merchant Reference (optional)
            'pstn_nr'   => 't',                                                     // Undocumented
            'data'      => $privateData,                                            // Data to be passed back to us
            'component' => $component,
            'qfKey'     => $params['qfKey'],
        );
        if 	($this->_mode == 'test'){
            $psParams['pstn_tm'] = 't';                                             // Test mode
        }
        $paystationParams = 'paystation&' . paystation_query_string_encode($psParams);

        CRM_Core_Error::debug_log_message('Paystation Params: ' . $paystationParams);
        if ( $initiationResult = directTransaction($paystationURL,$paystationParams) ) {
            $xml = simplexml_load_string($initiationResult);
            if (isset($xml)) {
                $result = (array) $xml;

                if (!empty($result['PaystationTransactionID'])) {
                    // the request was validated, so we'll get the URL and redirect to it
                    if (!empty($result['DigitalOrder'])) {
                        $uri = $result['DigitalOrder'];
                        CRM_Core_Error::debug_log_message('Paystation Redirect URI: ' . $uri);
                        CRM_Utils_System::redirect( $uri );
                    }
                    else {
                        // redisplay confirmation page
                        CRM_Core_Error::debug_log_message('Paystation XML: DigitalOrder element was empty.');
                        CRM_Utils_System::redirect( $cancelURL );
                    }
                }
                else {
                    // redisplay confirmation page
                    CRM_Core_Error::debug_log_message('Paystation XML: PaystationTransactionID element was empty.');
                    CRM_Utils_System::redirect( $cancelURL );
                }
            }
            else {
                // redisplay confirmation page
                CRM_Core_Error::debug_log_message('XML from paystation couldn\'t be loaded by simplexml.');
                CRM_Utils_System::redirect( $cancelURL );
            }
        }
        else {
            // calling Paystation failed
            CRM_Core_Error::fatal( ts( 'Unable to establish connection to the payment gateway.' ) );
        }
    }
}

