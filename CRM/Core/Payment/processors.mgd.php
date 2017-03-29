<?php
/**
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * http://wiki.civicrm.org/confluence/display/CRMDOC/Hook+Reference
 */
return array(
  0 => array(
    'name' => 'Paystation',
    'entity' => 'payment_processor_type',
    'params' => array(
      'version' => 3,
      'title' => 'Paystation',
      'name' => 'paystation',
      'description' => 'Paystation Payment Processor',
      'user_name_label' => 'Paystation ID',
      'password_label' => 'Gateway ID',
      'signature_label' => NULL,
      'class_name' => 'Payment_Paystation',
      'url_site_default' => 'https://www.paystation.co.nz/direct/paystation.dll',
      'url_api_default' => 'https://www.paystation.co.nz/lookup/quick/',
      'billing_mode' => 4,
      'payment_type' => 1,
    ),
  ),
);
