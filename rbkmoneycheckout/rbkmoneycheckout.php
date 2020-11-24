<?php

defined('_JEXEC') or die('Restricted access');
define('JPATH_BASE', dirname(__FILE__));
if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

/**
 * Class plgVMPaymentRbkmoneyCheckout
 *
 * @link https://rbkmoney.github.io/docs/
 * @link https://rbkmoney.github.io/api/
 * @link http://docs.virtuemart.net/api-vm2/d3/d6e/classplg_v_m_payment_systempay.html
 */
class plgVMPaymentRbkmoneyCheckout extends vmPSPlugin
{
    // ------------------------------------------------------------------------ 
    // Constants
    // ------------------------------------------------------------------------

    const MODULE_NAME = 'RBKmoney checkout';

    /**
     * Payment form
     */
    const PAYMENT_FORM_URL = 'https://checkout.rbk.money/checkout.js';
    const API_URL = 'https://api.rbk.money/v2/';


    /**
     * Create invoice settings
     */
    const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
    const CREATE_INVOICE_DUE_DATE = '+1 days';

    /**
     * HTTP status code
     */
    const HTTP_CODE_OK = 200;
    const HTTP_CODE_CREATED = 201;
    const HTTP_CODE_BAD_REQUEST = 400;

    const TYPE_MESSAGE = 'message';
    const TYPE_ERROR = 'error';

    /**
     * Constants for Callback
     */
    const SIGNATURE = 'HTTP_CONTENT_SIGNATURE';
    const SIGNATURE_PATTERN = "/alg=(\S+);\sdigest=/";

    const EVENT_TYPE = 'eventType';

    const INVOICE = 'invoice';
    const INVOICE_ID = 'id';
    const INVOICE_SHOP_ID = 'shopID';
    const INVOICE_METADATA = 'metadata';
    const INVOICE_STATUS = 'status';
    const INVOICE_AMOUNT = 'amount';

    const ORDER_ID = 'order_id';
    const SESSION_ID = 'session_id';


    /**
     * Openssl verify
     */
    const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;


    // ------------------------------------------------------------------------ 
    // Static
    // ------------------------------------------------------------------------

    // instance of class
    public static $_this = FALSE;


    /**
     * plgVMPaymentRbkmoneyCheckout constructor.
     *
     * @param object $subject The object to observe
     * @param array $config An array that holds the plugin configuration
     * @since 1.5
     */
    function __construct(&$subject = NULL, $config = NULL)
    {
        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->_debug = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = [
            'shop_id' => ['', 'string'],
            'private_key' => ['', 'string'],
            'form_company_name' => ['', 'string'],
            'callback_public_key' => ['', 'string'],
            'form_description' => ['', 'string'],
            'form_css_button' => ['', 'string'],
            'form_button_label' => ['', 'string'],

            'invoice_id' => ['', 'string'],

            'status_pending' => ['', 'char'],
            'status_success' => ['', 'char'],
            'status_canceled' => ['', 'char'],

            'countries' => [0, 'char'],

            'min_amount' => [0, 'int'],
            'max_amount' => [0, 'int'],

            'tax_id' => [0, 'int'],
        ];
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     *
     * @return string
     */
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Rbkmoney Checkout Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return array
     */
    function getTableSQLFields()
    {
        return [
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED',
            'order_number' => 'char(64)',
            'invoice_id' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\'',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
        ];
    }

    /**
     * Prepare data and redirect to Systempay payment platform
     *
     * @param $cart
     * @param $order
     *
     * @return bool|null
     */
    function plgVmConfirmedOrder($cart, $order)
    {
        $this->_logger(__METHOD__ . ' begin');
        if (!($this->_currentMethod = $this->getVmPluginMethod($this->_getPaymentMethodId($order)))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
        }

        /*** @var CurrencyDisplay $paymentCurrency * */
        $paymentCurrency = CurrencyDisplay::getInstance($this->_currentMethod->payment_currency);

        try {
            $response = $this->_createInvoice($order, $paymentCurrency);

            $invoice_id = $response["invoice"]["id"];
            $access_token = $response["invoiceAccessToken"]["payload"];
        } catch (Exception $ex) {
            $this->_logger(__METHOD__ . ' exception ' . $ex->getMessage());
            $errorMessage = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_INVOICE_CREATE_FRIENDLY_ERROR');
            JFactory::getApplication()->enqueueMessage($errorMessage, static::TYPE_ERROR);
            return;
        }

        $dbValues['order_number'] = $this->_getOrderId($order);
        $dbValues['virtuemart_order_id'] = $this->_getVirtueMartOrderId($order);
        $dbValues['virtuemart_paymentmethod_id'] = $this->_getPaymentMethodId($order);
        $dbValues['invoice_id'] = $invoice_id;
        $dbValues['payment_name'] = $this->_currentMethod->payment_name;
        $dbValues['payment_order_total'] = $this->_getTotalAmount($order, $paymentCurrency);
        $dbValues['payment_currency'] = $this->_currentMethod->payment_currency;
        $dbValues['cost_per_transaction'] = $this->_currentMethod->cost_per_transaction;
        $dbValues['cost_percent_total'] = $this->_currentMethod->cost_percent_total;
        $dbValues['tax_id'] = $this->_currentMethod->tax_id;
        $this->storePSPluginInternalData($dbValues);

        $formCompanyName = $this->_getFormCompanyName();
        $companyName = !empty($formCompanyName) ? 'data-name="' . $formCompanyName . '"' : '';

        $formButtonLabel = $this->_getFormButtonLabel();
        $buttonLabel = !empty($formButtonLabel) ? 'data-label="' . $formButtonLabel . '"' : '';

        $formDescription = $this->_getFormDescription();
        $description = !empty($formDescription) ? 'data-description="' . $formDescription . '"' : '';

        $email = '';
        if (!empty($order['details']['BT']) && !empty($order['details']['BT']->email)) {
            $email = 'data-email="' . $order['details']['BT']->email . '"';
        }

        $formCssButton = $this->_getFormCssButton();
        $style = !empty($formCssButton) ? '<style>' . $formCssButton . '</style>' : '';

        $formAction = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $this->_getOrderId($order) . '&pm=' . $this->_getPaymentMethodId($order));
        $form = '<form action="' . $formAction . '" method="POST">
                    <script src="' . static::PAYMENT_FORM_URL . '" class="rbkmoney-checkout"
                    data-invoice-id="' . $invoice_id . '"
                    data-invoice-access-token="' . $access_token . '"
                    ' . $companyName . '
                    ' . $buttonLabel . '
                    ' . $description . '
                    ' . $email . '
                    >
                    </script>
                </form>';

        $html = $style . $form;

        $this->_logger(__METHOD__ . ' end');
        $cart->emptyCart(); // We delete the old stuff

        JFactory::getApplication()->input->set('html', $html);
        return true;
    }

    /**
     * Get payment currency
     *
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     *
     * @return bool|null
     */
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency($this->_currentMethod);
        $paymentCurrencyId = $this->_currentMethod->payment_currency;
        return TRUE;
    }


    /**
     * Payment response received
     * e.g. JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=your_order_id&pm=payment_method_id
     *
     * @param $html
     *
     * @return bool|null|string
     */
    function plgVmOnPaymentResponseReceived(&$html)
    {
        $html =  vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_SUCCESS_PAGE');
        JFactory::getApplication()->input->set('html', $html);
        return TRUE;
    }

    /**
     * User payment Cancel
     * e.g. JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginuserpaymentcancel&on=your_order_id&pm=payment_method_id
     *
     * @return bool|null
     */
    function plgVmOnUserPaymentCancel()
    {
        $html =  vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_FAIL_PAGE');
        JFactory::getApplication()->input->set('html', $html);
        return TRUE;
    }

    /**
     * Payment notification
     * e.g. JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=rbkmoneycheckout
     *
     * @return bool|null
     */
    function plgVmOnPaymentNotification()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        $pm = JFactory::getApplication()->input->get('pm', '');
        if ($pm != 'rbkmoneycheckout' && empty($pm)) {
            return FALSE;
        }

        $content = file_get_contents('php://input');
        $logs = [
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'content' => $content,
            ],
        ];


        if (empty($_SERVER[static::SIGNATURE])) {
            $logs['error']['message'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_SIGNATURE_MISSING');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }

        $logs['signature'] = $_SERVER[static::SIGNATURE];


        $required_fields = [static::INVOICE, static::EVENT_TYPE];
        $data = json_decode($content, TRUE);
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $logs['error']['message'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_REQUIRED_FIELDS');
                $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
            }
        }


        if (empty($data[static::INVOICE][static::INVOICE_METADATA][static::ORDER_ID])) {
            $logs['error']['message'] = static::ORDER_ID . vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_FIELD_MISSING');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }


        $order_number = $data[static::INVOICE][static::INVOICE_METADATA][static::ORDER_ID];
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
        $payment = $this->getDataByOrderId($virtuemart_order_id);
        if (!$payment) {
            $logs['error']['message'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_PAYMENT_NOT_FOUND');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }


        $this->_currentMethod = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        $logs['_current_method'] = print_r($this->_currentMethod, true);

        $signature_from_header = $this->getSignatureFromHeader($_SERVER[static::SIGNATURE]);
        $decoded_signature = $signature = $this->urlSafeB64decode($signature_from_header);
        if (!$this->verificationSignature($content, $decoded_signature, $this->_getCallbackPublicKey())) {
            $logs['public_key'] = $this->_getCallbackPublicKey();
            $logs['error']['message'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_SIGNATURE_MISMATCH');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }


        if (!($this->_currentMethod = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id))) {
            $logs['error']['message'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_PAYMENT_METHOD_NOT_FOUND');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }

        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            $logs['error']['message'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_PAYMENT_ELEMENT_NOT_FOUND');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }

        $current_shop_id = $this->_getShopId();
        if ($data[static::INVOICE][static::INVOICE_SHOP_ID] != $current_shop_id) {
            $logs['error']['message'] = static::INVOICE_SHOP_ID . vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_FIELD_MISSING');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }


        if (empty($data[static::INVOICE][static::INVOICE_METADATA][static::SESSION_ID])) {
            $logs['error']['message'] = static::SESSION_ID . vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_FIELD_MISSING');
            $this->outputWithLogger(__METHOD__, $logs, $logs['error']['message']);
        }

        $order = [];
        $order['virtuemart_order_id'] = $virtuemart_order_id;
        $order['customer_notified'] = 1;

        $invoiceId = $data[static::INVOICE][static::INVOICE_ID];

        switch ($data[static::INVOICE][static::INVOICE_STATUS]) {
            case 'paid':
                $logs['order_payment'] = 'Order has been paid';
                $order['comments'] = JTExt::sprintf('Your payment for order %s has been confirmed by RBKmoney (%s)', $order_number, $invoiceId);
                $order['order_status'] = $this->_currentMethod->status_success;
                break;
            case 'cancelled':
                $logs['order_payment'] = 'Order Cancelled';
                $order['comments'] = JTExt::sprintf('Your payment for order %s has been cancelled by RBKmoney (%s)', $order_number, $invoiceId);
                $order['order_status'] = $this->_currentMethod->status_canceled;
                break;
            default:
                $order['order_status'] = $this->_currentMethod->status_pending;
        }

        $modelOrder = new VirtueMartModelOrders();
        $this->_logger(__METHOD__ . ' ' . print_r(['logs' => $logs, 'order' => $order,], true));
        $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);

        // remove vmcart
        $sessionId = $data[static::INVOICE][static::INVOICE_METADATA][static::SESSION_ID];
        $this->emptyCart($sessionId);
        $this->outputWithLogger(__METHOD__, $logs, 'OK', static::HTTP_CODE_OK, static::TYPE_MESSAGE);
        return TRUE;
    }

    /**
     * Get costs
     *
     * @param VirtueMartCart $cart
     * @param $method
     * @param $cart_prices
     *
     * @return mixed
     */
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart
     * @param $method
     * @param $cart_prices
     *
     * @return boolean
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->_logger(__METHOD__ . ' begin');
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ($method->min_amount <= $amount AND ($method->max_amount == 0)));

        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (in_array($address['virtuemart_country_id'],
                $countries) || count($countries) == 0
        ) {
            if ($amount_cond) {
                return TRUE;
            }
        }
        $this->_logger(__METHOD__ . ' end');
        return FALSE;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @param $jplugin_id
     *
     * @return bool|mixed
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store additional payment info in the cart.
     *
     * @param VirtueMartCart $cart
     *
     * @return null
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * This shows the plugin for choosing in the payment list of the checkout process.
     *
     * @param VirtueMartCart $cart
     * @param int $selected
     * @param $htmlIn
     *
     * @return bool
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     *
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param $cart_prices_name
     *
     * @return bool|null
     */
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param                $paymentCounter
     *
     * @return
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $order_number The order ID
     * @param integer $method_id method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Save updated order data to the method specific table
     *
     * @param $name
     * @param $id
     * @param $data
     * @return bool
     */
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    /**
     * Save updated order data to the method specific table
     *
     * @param $data
     * @return bool
     */
    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * We must reimplement this triggers for joomla 1.7 Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @param $name
     * @param $id
     * @param $table
     * @return bool
     */
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    // ------------------------------------------------------------------------ 
    // Getters Virtue Mart
    // ------------------------------------------------------------------------

    private function _getOrderId($order)
    {
        return $order['details']['BT']->order_number;
    }

    private function _getVirtueMartOrderId($order)
    {
        return $order['details']['BT']->virtuemart_order_id;
    }

    private function _getPaymentMethodId($order)
    {
        return $order['details']['BT']->virtuemart_paymentmethod_id;
    }

    private function _getSessionId()
    {
        return JFactory::getSession()->getId();
    }

    private function _getTotalAmount($order, $paymentCurrency)
    {
        /*** @var CurrencyDisplay $paymentCurrency * */
        return round(
            $paymentCurrency->convertCurrencyTo(
                $this->_currentMethod->payment_currency,
                $order['details']['BT']->order_total,
                FALSE
            ),
            2
        );
    }

    /**
     * @param $paymentCurrency
     * @return string
     */
    private function _getCurrencySymbolicCode($paymentCurrency)
    {
        /*** @var CurrencyDisplay $paymentCurrency * */
        return $paymentCurrency->_vendorCurrency_code_3;
    }


    // ------------------------------------------------------------------------ 
    // Getters RBKmoney
    // ------------------------------------------------------------------------

    private function _getShopId()
    {
        return $this->_currentMethod->shop_id;
    }

    private function _getPrivateKey()
    {
        $private_key = $this->_currentMethod->private_key;
        return trim(strip_tags($private_key));
    }

    private function _getFormDescription()
    {
        $form_description = $this->_currentMethod->form_description;
        return trim(strip_tags($form_description));
    }

    private function _getFormCssButton()
    {
        $form_css_button = $this->_currentMethod->form_css_button;
        return trim(strip_tags($form_css_button));
    }

    private function _getFormButtonLabel()
    {
        $form_button_label = $this->_currentMethod->form_button_label;
        return trim(strip_tags($form_button_label));
    }

    private function _getFormCompanyName()
    {
        $form_company_name = $this->_currentMethod->form_company_name;
        return trim(strip_tags($form_company_name));
    }

    private function _getCallbackPublicKey()
    {
        $callback_public_key = $this->_currentMethod->callback_public_key;
        return trim(strip_tags($callback_public_key));
    }


    // ------------------------------------------------------------------------ 
    // RBKmoney private methods 
    // ------------------------------------------------------------------------

    /**
     * Create invoice
     *
     * @param $order
     * @param $paymentCurrency
     *
     * @return string
     * @throws Exception
     */
    private function _createInvoice($order, $paymentCurrency)
    {
        $data = [
            'shopID' => $this->_getShopId(),
            'amount' => $this->_prepareAmount($this->_getTotalAmount($order, $paymentCurrency)),
            'metadata' => $this->_prepareMetadata($order),
            'dueDate' => $this->_prepareDueDate(),
            'currency' => $this->_getCurrencySymbolicCode($paymentCurrency),
            'product' => $this->_getOrderId($order),
            'description' => $this->_productDetails($order),
            'cart' => $this->_prepareCart($order),
        ];

        $url = $this->_prepareApiUrl('processing/invoices');
        $response = $this->_send($url, $this->_getHeaders(), json_encode($data), 'init_invoice');

        if ($response['http_code'] != static::HTTP_CODE_CREATED) {
            $message = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_INVOICE_CREATE_ERROR');
            $this->_logger($message, 'ERROR');
            throw new Exception($message);
        }

        return json_decode($response['body'], true);
    }

    /**
     * Send request
     *
     * @param string $url
     * @param array $headers
     * @param string $data
     * @param string $type
     *
     * @return array
     */
    function _send($url = '', $headers = array(), $data = '', $type = '')
    {
        $request = [
            'url' => $url,
            'method' => 'POST',
            'body' => $data,
            'headers' => $headers,
        ];
        $this->_logger(__METHOD__ . ' begin. ' . $type . ' ' . print_r($request, true));

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($curl);
        $info = curl_getinfo($curl);
        $curlErrNo = curl_errno($curl);

        $response = [
            'http_code' => $info['http_code'],
            'body' => $body,
            'error' => $curlErrNo,
        ];
        $logs = [
            'request' => $request,
            'response' => $response,
        ];
        $this->_logger(__METHOD__ . ' end. ' . $type . ' ' . print_r($logs, true));
        curl_close($curl);

        return $response;
    }

    /**
     * Get headers
     *
     * @return array
     */
    private function _getHeaders()
    {
        $headers = [];
        $headers[] = 'X-Request-ID: ' . uniqid();
        $headers[] = 'Authorization: Bearer ' . $this->_getPrivateKey();
        $headers[] = 'Content-type: application/json; charset=utf-8';
        $headers[] = 'Accept: application/json';
        return $headers;
    }

    /**
     * Prepare metadata
     *
     * @param array $order Object
     *
     * @return array
     */
    private function _prepareMetadata($order)
    {
        return [
            'cms' => vmVersion::$PRODUCT,
            'cms_version' => vmVersion::$RELEASE,
            'module' => static::MODULE_NAME,
            'order_id' => $this->_getOrderId($order),
            'session_id' => $this->_getSessionId(),
        ];
    }


    /**
     * Prepare cart
     *
     * @param array $order Object
     *
     * @return array
     */
    private function _prepareCart($order)
    {
        $items = $this->_prepareItemsForCart($order);
        $shipping = $this->_prepareShippingForCart($order);
        return array_merge($shipping, $items);

    }

    private function _prepareItemsForCart($order)
    {
        $lines = array();

        foreach ($order['items'] as $product) {
            $item = array();

            if($product->product_final_price > 0.0) {
                $item['product'] = $product->product_name;
                $item['quantity'] = (int)$product->product_quantity;

                $amount = $product->product_final_price;
                $price = number_format($amount, 2, '.', '');
                $item['price'] = $this->_prepareAmount(round($price, 2));

                if (!empty($product->allPrices[0]['VatTax'][1][1])) {

                    $taxRate = $this->_getTaxRate($product->allPrices[0]['VatTax'][1][1]);
                    if($taxRate != null) {
                        $taxMode = array(
                            'type' => 'InvoiceLineTaxVAT',
                            'rate' => $taxRate
                        );

                        $item['taxMode'] = $taxMode;
                    }
                }

                $lines[] = $item;
            }
        }

        return $lines;
    }

    private function _prepareShippingForCart($order)
    {
        $lines = array();
        if(!empty($order['details']['BT']->order_shipment) && $order['details']['BT']->order_shipment > 0) {
            $item['product'] = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_SHIPMENT');

            $price = $order['details']['BT']->order_shipment + $order['details']['BT']->order_shipment_tax;
            $item['price'] = $this->_prepareAmount(round($price, 2));
            $item['quantity'] = 1;

            $taxRate = $this->_getShipmentTaxRate($order);
            if (!empty($taxRate)) {

                $taxRate = $this->_getTaxRate($taxRate);
                if($taxRate != null) {
                    $taxMode = array(
                        'type' => 'InvoiceLineTaxVAT',
                        'rate' => $taxRate
                    );
                    $item['taxMode'] = $taxMode;
                }
            }

            $lines[] = $item;
        }

        return $lines;
    }

    private function _getShipmentTaxRate($order) {
        foreach ($order['calc_rules'] as $product) {
            if ($product->calc_kind == 'shipment') {
                return $product->calc_value;
            }
        }

        return null;
    }

    /**
     * Get tax rate
     *
     * @param $rate
     * @return null|string
     */
    private function _getTaxRate($rate)
    {
        switch ($rate) {
            // НДС чека по ставке 0%;
            case 0:
                return '0%';
                break;
            // НДС чека по ставке 10%;
            case 10:
                return '10%';
                break;
            // НДС чека по ставке 18%;
            case 18:
                return '18%';
                break;
            default: # — без НДС;
                return null;
                break;
        }
    }

    /**
     * Product details
     *
     * @param array $order
     *
     * @return string
     */
    private function _productDetails($order)
    {
        return "";
    }

    /**
     * Prepare due date
     *
     * @return string
     */
    private function _prepareDueDate()
    {
        date_default_timezone_set('UTC');
        return date(static::CREATE_INVOICE_TEMPLATE_DUE_DATE, strtotime(static::CREATE_INVOICE_DUE_DATE));
    }

    /**
     * Prepare amount (e.g. 124.24 -> 12424)
     *
     * @param $amount int
     *
     * @return int
     */
    private function _prepareAmount($amount)
    {
        return $amount * 100;
    }

    /**
     * Prepare api URL
     *
     * @param string $path
     * @param array $query_params
     *
     * @return string
     */
    private function _prepareApiUrl($path = '', $query_params = [])
    {
        $url = rtrim(static::API_URL, '/') . '/' . $path;
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }
        return $url;
    }


    public function urlSafeB64decode($string)
    {
        return base64_decode(strtr($string, '-_,', '+/='));
    }

    public function getSignatureFromHeader($content_signature)
    {
        $signature = preg_replace(static::SIGNATURE_PATTERN, '', $content_signature);

        if (empty($signature)) {
            $message = vmText::_('VMPAYMENT_RBKMONEY_CHECKOUT_WEBHOOK_SIGNATURE_MISSING');
            throw new Exception($message);
        }

        return $signature;
    }

    /**
     * Verification signature
     *
     * @param string $data
     * @param string $signature
     * @param string $public_key
     *
     * @return bool
     */
    public function verificationSignature($data = '', $signature = '', $public_key = '')
    {
        if (empty($data) || empty($signature) || empty($public_key)) {
            return FALSE;
        }
        $public_key_id = openssl_get_publickey($public_key);
        if (empty($public_key_id)) {
            return FALSE;
        }
        $verify = openssl_verify($data, $signature, $public_key_id, OPENSSL_ALGO_SHA256);
        return ($verify == static::OPENSSL_VERIFY_SIGNATURE_IS_CORRECT);
    }

    // ------------------------------------------------------------------------ 
    // Other private methods 
    // ------------------------------------------------------------------------

    /**
     * Logger
     *
     * Path "administrator/index.php?option=com_virtuemart&view=log" to store log information
     *
     * @param $text
     * @param string $type
     * @param bool $doLog
     */
    private function _logger($text, $type = self::TYPE_MESSAGE, $doLog = false)
    {
        $this->logInfo($text, $type, $doLog);
    }

    /**
     * Output with logger
     *
     * @param $method
     * @param $logs
     * @param $message
     * @param int $header
     */
    private function outputWithLogger($method, &$logs, $message, $header = self::HTTP_CODE_BAD_REQUEST, $type = self::TYPE_ERROR)
    {
        $response = array('message' => $message);
        $this->_logger($method . PHP_EOL . print_r($logs, true), $type);
        http_response_code($header);
        echo json_encode($response);
        exit();
    }

}
