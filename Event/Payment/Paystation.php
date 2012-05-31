<?php 

/*
 * Paystation Functionality Copyright (C) 2010 Elliot Pahl, Catalyst IT Limited
 * @license http://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License, version 3
 */

require_once('CRM/Core/Payment/Paystation.php');

class CRM_Event_Payment_Paystation extends CRM_Core_Payment_Paystation { 
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
        parent::__construct( $mode, $paymentProcessor );
    }

    /** 
     * singleton function used to manage this object 
     * 
     * @param string $mode the mode of operation: live or test
 
     * @return object 
     * @static 
     * 
     */ 
    static function &singleton( $mode, &$paymentProcessor ) {
        if (self::$_singleton === null ) { 
            self::$_singleton =& new CRM_Event_Payment_Paystation( $mode, $paymentProcessor );
        } 
        return self::$_singleton; 
    } 

    /**  
     * Sets appropriate parameters for checking out to google
     *  
     * @param array $params  name value pair of contribution datat
     *  
     * @return void  
     * @access public 
     *  
     */  
    function doTransferCheckout( &$params ) {
        parent::doTransferCheckout( $params, 'event' );
    }

}
