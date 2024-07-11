<?php

/**
 * UddoktaPay Gateway
 *
 * Allows users to pay via BD Payment Methods
 *
 */

class Uddoktapayint extends NonmerchantGateway
{
    private $meta;

    public function __construct()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'UddoktaPayIntAPI.php');

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Loader::loadComponents($this, ['Input']);

        Language::loadLang('uddoktapayint', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    public function getSettings(array $meta = null)
    {
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView(
            'components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'uddoktapayint' . DS
        );

        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        $rules = [
            'api_key'       => [
                'valid' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('UddoktaPayInt.!error.api_key.valid', true),
                ],
            ],
            'api_url'       => [
                'valid' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('UddoktaPayInt.!error.api_url.valid', true),
                ],
            ],
            'exchange_rate' => [
                'valid' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('UddoktaPayInt.!error.exchange_rate.valid', true),
                ],
            ],
        ];
        $this->Input->setRules($rules);

        $this->Input->validates($meta);

        return $meta;
    }

    public function encryptableFields()
    {
        return ['api_key', 'api_url'];
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        Loader::loadModels($this, ['Companies']);

        $formatAmount = round($amount, 2);

        if (isset($options['recur']['amount'])) {
            $options['recur']['amount'] = round($options['recur']['amount'], 2);
        }

        $currency = ($this->currency ?? null);

        if (strtoupper($currency) !== 'USD') {
            $formatAmount /= $this->meta['exchange_rate'];
        }

        if (isset($invoice_amounts) && is_array($invoice_amounts)) {
            $invoices = $this->serializeInvoices($invoice_amounts);
        }

        $notification_url = Configure::get('Blesta.gw_callback_url')
        . Configure::get('Blesta.company_id') . '/uddoktapayint/?client_id='
            . (isset($contact_info['client_id']) ? $contact_info['client_id'] : null);

        $payment = [
            'full_name'    => ($contact_info['first_name'] ?? '') . ' ' . ($contact_info['last_name'] ?? ''),
            'email'        => $this->emailFromClientId($contact_info['client_id']),
            'amount'       => $formatAmount,
            'metadata'     => [
                'customer_id' => ($contact_info['client_id'] ?? null),
                'invoices'    => $invoices,
                'currency'    => $currency,
                'amount'      => $amount,
            ],
            'redirect_url' => $notification_url,
            'return_type'  => 'GET',
            'cancel_url'   => ($options['return_url'] ?? null),
            'webhook_url'  => $notification_url,
        ];

        try {
            $api = $this->getApi();
            $paymentUrl = $api->initPayment($payment);
            header('Location:' . $paymentUrl);
            exit();
        } catch (\Exception $e) {
            die("Initialization Error: " . $e->getMessage());
        }
    }

    private function emailFromClientId($id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $contact = $this->Record->select(['contacts.email'])
            ->from('contacts')
            ->where('contacts.contact_type', '=', 'primary')
            ->where('contacts.client_id', '=', $id)
            ->fetch();
        if ($contact) {
            return $contact->email;
        }
        return null;
    }

    public function validate(array $get, array $post)
    {
        $invoice_id = $get['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            $up_response = file_get_contents('php://input');
            $up_response_decode = @json_decode($up_response, true);
            $invoice_id = $up_response_decode['invoice_id'] ?? '';
        }

        $status = 'pending';
        $success = false;

        if (!empty($invoice_id)) {
            try {
                $api = $this->getApi();
                $response = $api->verifyPayment($invoice_id);
            } catch (\Exception $e) {
                return;
            }

            if ($response['status'] === 'COMPLETED') {
                $status = 'approved';
                $success = true;
            }
        }

        if (!$success) {
            return;
        }

        return [
            'client_id'             => ($response['metadata']['customer_id'] ?? null),
            'amount'                => $response['metadata']['amount'],
            'currency'              => $response['metadata']['currency'],
            'invoices'              => $this->unserializeInvoices($response['metadata']['invoices'] ?? null),
            'status'                => $status,
            'reference_id'          => null,
            'transaction_id'        => $response['transaction_id'],
            'parent_transaction_id' => null,
        ];
    }

    public function success(array $get, array $post)
    {
        $invoice_id = $get['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            $up_response = file_get_contents('php://input');
            $up_response_decode = @json_decode($up_response, true);
            $invoice_id = $up_response_decode['invoice_id'] ?? '';
        }

        $status = 'pending';
        $success = false;

        if (!empty($invoice_id)) {
            try {
                $api = $this->getApi();
                $response = $api->verifyPayment($invoice_id);
            } catch (\Exception $e) {
                return;
            }

            if ($response['status'] === 'COMPLETED') {
                $status = 'approved';
                $success = true;
            }
        }

        if (!$success) {
            return;
        }

        return [
            'client_id'             => ($response['metadata']['customer_id'] ?? null),
            'amount'                => $response['metadata']['amount'],
            'currency'              => $response['metadata']['currency'],
            'invoices'              => $this->unserializeInvoices($response['metadata']['invoices'] ?? null),
            'status'                => $status,
            'transaction_id'        => $response['transaction_id'],
            'parent_transaction_id' => null,
        ];
    }

    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    private function getApi()
    {
        return new UddoktaPayIntAPI($this->meta['api_key'], $this->meta['api_url']);
    }

    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return base64_encode($str);
    }

    private function unserializeInvoices($str)
    {
        if (empty($str)) {
            return null;
        }

        $str = base64_decode($str);

        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }
}
