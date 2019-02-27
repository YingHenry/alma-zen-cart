<?php

/**
 * Class of alma payment.
 */
class alma {

  /**
   * Defines infos for admin dashboard, weight in payment choice and form
   * action. Overrides status if geography is wrong.
   */
  public function __construct() {
    global $order;

    $this->code = 'alma';
    $this->title = MODULE_PAYMENT_ALMA_TEXT_TITLE;
    $this->description = MODULE_PAYMENT_ALMA_TEXT_DESCRIPTION;

    // Yellow light if defined.
    $this->sort_order = defined('MODULE_PAYMENT_ALMA_SORT_ORDER') ? MODULE_PAYMENT_ALMA_SORT_ORDER : NULL;

    // Green light if sort_order is defined and enabled equals true.
    $this->enabled = MODULE_PAYMENT_ALMA_STATUS == 'True' ? TRUE : FALSE;

    if (is_object($order)) {
      $this->update_status();
    }

    // The payment page.
    $this->form_action_url = '';
  }

  /**
   * Builds process button.
   *
   * @return string
   *   The process button.
   */
  public function process_button() {
    // TODO: redirect to alma payment page.

    $processButtonString = '';

    return $processButtonString;
  }

  /**
   * Disables payment if customer's zone is not in payment zone.
   */
  public function update_status() {
    global $order, $db;

    // If payment is enabled and is restricted to one geozone (country + zone)
    if ($this->enabled && (int)MODULE_PAYMENT_ALMA_ZONE > 0 ) {
      $check_flag = FALSE;
      $checks = $db->Execute( "select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_ALMA_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id" );

      foreach ($checks as $check) {
        // If zone_id is null, payment is available for all zones.
        if ($check['zone_id'] == NULL) {
          $check_flag = TRUE;
          break;
        }

        // If State/Province of customer is the payment zone.
        if ($check['zone_id'] == $order->billing['zone_id']) {
          $check_flag = TRUE;
          break;
        }
      }

      if ($check_flag == FALSE) {
        $this->enabled = FALSE;
      }
    }
  }

  public function javascript_validation() {
    return FALSE;
  }

  /**
   * Shows payment module in step 2.
   *
   * @return array
   *   Module infos.
   */
  public function selection() {
    $infos = [
      'id' => $this->code,
      'module' => $this->title,
    ];

    return $infos;
  }

  public function pre_confirmation_check() {
    return FALSE;
  }

  public function confirmation() {
    return FALSE;
  }

  /**
   * Actions after the transaction.
   */
  public function before_process() {
    global $messageStack;

    // TODO: Queries the server about the transaction.
    $success = TRUE;

    if ($success) {
      $this->order_status = 1;
    }
    else {
      $messageStack->add_session('checkout_payment', MODULE_PAYMENT_ALMA_TEXT_ERROR, 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', TRUE, FALSE));
    }
  }

  /**
   * Updates the order status history.
   *
   * @return boolean
   */
  public function after_process() {
    global $insert_id, $db, $order, $currencies;

    $keys = "(comments, orders_id, orders_status_id, customer_notified, date_added)";
    $values = "(:comments, :orderID, :orderStatusId, -1, now())";
    $sql = "insert into " . TABLE_ORDERS_STATUS_HISTORY . " " . $keys . " values " . $values;
    $comments = "My comment";
    $sql = $db->bindVars( $sql, ':comments', $comments, 'string' );
    $sql = $db->bindVars( $sql, ':orderID', $insert_id, 'integer' );
    $sql = $db->bindVars( $sql, ':orderStatusId', $this->order_status, 'integer' );
    $db->Execute( $sql );

    return TRUE;
  }

  /**
   * Returns error.
   *
   * @return array
   *   The error.
   */
  public function get_error() {
    global $HTTP_GET_VARS;

    $data = [
      'title' => MODULE_PAYMENT_PAYGATEPAYWEB3_TEXT_ERROR,
      'error' => stripslashes(urldecode($HTTP_GET_VARS['error']))
    ];

    return $data;
  }

  /**
   * If module is not installed, shows install button. Shows edit button else.
   */
  public function check() {
    global $db;

    if (!isset($this->_check)) {
      $check_query  = $db->Execute( "select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_ALMA_STATUS'" );
      $this->_check = $check_query->RecordCount();
    }

    return $this->_check;
  }

  /**
   * Adds configuration variables in database.
   */
  public function install() {
    global $db;

    $keys = "(configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)";

    $values = "('Enable alma Module.', 'MODULE_PAYMENT_ALMA_STATUS', 'True', 'Do you want to accept PayPal payments?', '6', 0, '', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())";
    $db->Execute("insert into " . TABLE_CONFIGURATION . " " . $keys . "values" . $values);

    $values = "('Sort order of display.', 'MODULE_PAYMENT_ALMA_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', '', '', now())";
    $db->Execute("insert into " . TABLE_CONFIGURATION . " " . $keys . "values" . $values);

    $values = "('Payment Zone', 'MODULE_PAYMENT_ALMA_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '0', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())";
    $db->Execute("insert into " . TABLE_CONFIGURATION . " " . $keys . "values" . $values);
  }

  /**
   * Removes configuration variables from database.
   */
  public function remove() {
    global $db;

    $db->Execute( "delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode( "', '", $this->keys() ) . "')" );
  }

  /**
   * Returns configuration keys.
   *
   * @return array
   *   The keys.
   */
  public function keys() {
    $keys = [
      'MODULE_PAYMENT_ALMA_STATUS',
      'MODULE_PAYMENT_ALMA_SORT_ORDER',
      'MODULE_PAYMENT_ALMA_ZONE',
    ];

    return $keys;
  }

}
