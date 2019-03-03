<?php

require_once __DIR__ . '/alma-php-client/vendor/autoload.php';

use Alma\API\RequestError;
use Alma\API\Client;

/**
 * Class of alma payment.
 */
class alma {

  /**
   * Alma client.
   *
   * @var \Alma\API\Client
   */
  protected $alma;

  /**
   * Payment data.
   *
   * @var array
   */
  protected $paymentData;

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

    // Payment data.
    if (!empty($order->info['total'])) {
      $total = (int)($order->info['total'] * 100);
    }
    else {
      $total = 0;
    }

    $this->paymentData = [
      'payment' => [
        'purchase_amount' => $total,
        'return_url' => zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL', TRUE, FALSE),
        'shipping_address' => [
          'line1' => !empty($order->delivery['street_address']) ? $order->delivery['street_address'] : '',
          'city' => !empty($order->delivery['city']) ? $order->delivery['city'] : '',
          'postal_code' => !empty($order->delivery['postcode']) ? $order->delivery['postcode'] : '',
        ],
      ],
      'customer' => [
        'first_name' => !empty($order->customer['firstname']) ? $order->customer['firstname'] : '',
        'last_name' => !empty($order->customer['lastname']) ? $order->customer['lastname'] : '',
        'email' => !empty($order->customer['email_address']) ? $order->customer['email_address'] : '',
        'phone' => !empty($order->customer['telephone']) ? $order->customer['telephone'] : '',
        'adresses' => [
          [
            'line1' => !empty($order->customer['street_address']) ? $order->customer['street_address'] : '',
            'city' => !empty($order->customer['city']) ? $order->customer['city'] : '',
            'postal_code' => !empty($order->customer['postcode']) ? $order->customer['postcode'] : '',
          ],
        ],
      ],
    ];

    // Alma client.
    if (defined('MODULE_PAYMENT_ALMA_API_KEY_TEST') && MODULE_PAYMENT_ALMA_API_KEY_TEST) {
      $this->alma = new Client(trim(MODULE_PAYMENT_ALMA_API_KEY_TEST), ['mode' => Alma\API\TEST_MODE]);
    }
    else {
      $this->enabled = FALSE;
    }

    if (is_object($order)) {
      $this->update_status();
    }

    // The payment page.
    $this->form_action_url = '';
  }

  /**
   * Disables payment if customer's zone is not in payment zone in step 2.
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

    $this->checkEligibility();
  }

  /**
   * Checks eligibility of an order.
   *
   * @throws \Alma\API\RequestError
   */
  public function checkEligibility() {
    if ($this->enabled) {
      $eligibility = $this->alma->payments->eligibility($this->paymentData);

      if (!$eligibility->isEligible) {
        $this->enabled = FALSE;
      }
    }
  }

  /**
   * Builds process button in step 3.
   *
   * @return string
   *   The process button.
   */
  public function process_button() {
    global $messageStack;

    // Order is eligible, tries to create payment.
    try {
      $payment = $this->alma->payments->createPayment($this->paymentData);

      // Redirects to alma payment page.
      $processButtonString = '
        <script type="text/javascript">
          document.addEventListener("DOMContentLoaded", function() {
            [].forEach.call(document.querySelectorAll("#btn_submit"), function(el) {
              el.addEventListener("click", function(evt) {
                location.href = "' . $payment->url . '";
                evt.preventDefault(); // Prevents form submit.
              })
            })
          });
        </script>
      ';
    }
    catch (RequestError $error) {
      // Issue: redirect from step 3 is buggy. The page must be refreshed.
      $messageStack->add_session('checkout_payment', 'Shipping adress or billing address is missing', 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', TRUE, FALSE));
    }

    return $processButtonString;
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
    global $messageStack, $order;

    // Queries the server about the transaction.
    $success = FALSE;

    if (!empty($_GET['pid'])) {
      // 404 if not found.
      $pid = $_GET['pid'];

      try {
        $this->payment = $this->alma->payments->fetch($pid);
        $state = $this->payment->state == 'in_progress' || $this->payment->state == 'paid';

        // Data initialized in constructor are empty...
        if (!empty($order->info['total'])) {
          $total = (int)($order->info['total'] * 100);
        }
        else {
          $total = 0;
        }

        $purchaseAmount = $this->payment->purchase_amount == $total;
        $paymentPlanState = $this->payment->payment_plan[0]->state == 'paid';

        if ($state &&
          $purchaseAmount &&
          $paymentPlanState
        ) {
          $success = TRUE;
        }
      }
      catch (RequestError $error) {
        foreach ($error->response->json['errors'] as $error) {
          $messageStack->add_session('checkout_payment', $error['error_code'], 'error');
        }
      }
    }
    else {
      $messageStack->add_session('checkout_payment', MODULE_PAYMENT_ALMA_NO_PID_ERROR, 'error');
    }

    if ($success) {
      $this->order_status = 1;
    }
    else {
      // If payment failed redirect to payment choice page (step 2).
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', TRUE, FALSE));
    }
  }

  /**
   * Updates the order status history.
   *
   * @return boolean
   */
  public function after_process() {
    global $insert_id, $db;

    // Sets customer_notified to -1 so he cannot see it.
    $keys = "(comments, orders_id, orders_status_id, customer_notified, date_added)";
    $values = "(:comments, :orderID, :orderStatusId, -1, now())";
    $sql = "insert into " . TABLE_ORDERS_STATUS_HISTORY . " " . $keys . " values " . $values;
    $comments = "ID: " . $this->payment->id;
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
      'title' => MODULE_PAYMENT_ALMA_TEXT_ERROR,
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

    $values = "('Enable alma Module.', 'MODULE_PAYMENT_ALMA_STATUS', 'True', 'Do you want to use alma?', '6', 0, '', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())";
    $db->Execute("insert into " . TABLE_CONFIGURATION . " " . $keys . "values" . $values);

    $values = "('Sort order of display.', 'MODULE_PAYMENT_ALMA_SORT_ORDER', '0', 'Sort order of display. Lowest is on top.', '6', '0', '', '', now())";
    $db->Execute("insert into " . TABLE_CONFIGURATION . " " . $keys . "values" . $values);

    $values = "('Payment Zone', 'MODULE_PAYMENT_ALMA_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '0', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())";
    $db->Execute("insert into " . TABLE_CONFIGURATION . " " . $keys . "values" . $values);

    $values = "('API key', 'MODULE_PAYMENT_ALMA_API_KEY_TEST', '', 'You API key.', '6', '0', '', '', now())";
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
      'MODULE_PAYMENT_ALMA_API_KEY_TEST',
    ];

    return $keys;
  }

}
