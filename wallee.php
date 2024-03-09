<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;
use App\Controllers\Repositories\PaymentMethodRepository;
use App\Controllers\Repositories\CustomerWalleeTokensRepository;
use App\Controllers\Repositories\PaymentTransactionsRepository;

class WallePaymentGatewayController extends BaseController
{


    use ResponseTrait;
    private $DB;
    private $PaymentMethodRepository;
    private $CustomerWalleeTokensRepository;
    public $PaymentTransactionsRepository;
    public $request;
    public $session;

    public function __construct()
    {
        $this->DB = \Config\Database::connect();
        $this->request = \Config\Services::request();
        $this->session = \Config\Services::session();
        $this->User_PropertyID = session()->get('H_PROPERTY_ID');
        $this->PaymentMethodRepository = new PaymentMethodRepository();
        $this->CustomerWalleeTokensRepository = new CustomerWalleeTokensRepository();
        $this->PaymentTransactionsRepository = new PaymentTransactionsRepository();

    }

    /**
     * Retrieves WALLEE API credentials from environment variables.
     *
     * This method retrieves the WALLEE API credentials (space ID, user ID, and secret)
     * from the environment variables and returns them as an associative array.
     *
     * @return array An associative array containing the WALLEE API credentials.
     */
    private function getWalleeApiCredentials()
    {

        $userId = getenv("WALLEE_MERCHANT_USER_ID");
        $spaceId = getenv("WALLEE_MERCHANT_SPACE_ID");
        $secret = getenv("WALLEE_MERCHANT_SECRET");
        $terminal = getenv("WALLEE_TERMINAL");

        return [
            'spaceId' => $spaceId,
            'userId' => $userId,
            'secret' => $secret,
            'terminal' => $terminal
        ];
    }

    /**
     * Create line items for Wallee transactions.
     *
     * @param array $orderItems The array of order items.
     * @return array An array of LineItemCreate objects.
     */
    private function createLineItems(array $items, string $type): array
    {
        $invoiceArray = [];
        $nameProperty = '';
        $amountProperty = '';

        switch ($type) {
            case 'billing':
                $nameProperty = 'productName';
                $amountProperty = 'paidAmount';
                break;

            case 'webshop':
                $nameProperty = 'treatment';
                $amountProperty = 'price';
                break;

            case 'cashier':
                $nameProperty = 'name';
                $amountProperty = 'price';
                break;
            
            case 'guest-app':
                $nameProperty = 'name';
                $amountProperty = 'price';
                break;

            default:
                $nameProperty = 'name';
                $amountProperty = 'price';
                break;
        }

        foreach ($items as $item) { 
            $uniqueId = uniqid('', true);
            $lineItem = new \Wallee\Sdk\Model\LineItemCreate();
            $lineItem->setName($item[$nameProperty] ?? 'Web Shop Order');
            $lineItem->setUniqueId($uniqueId);
            $lineItem->setQuantity($item['quantity'] ?? 1);
            $lineItem->setAmountIncludingTax(number_format($item[$amountProperty], 2));
            $lineItem->setType(\Wallee\Sdk\Model\LineItemType::PRODUCT);
            $invoiceArray[] = $lineItem;
        }

        return $invoiceArray;
    }

    /**
     * Create Transaction Object for Wallee transactions.
     *
     * @param array $transactionPayload The array of order items.
     * @return array An array of transactionPayload objects.
     */

    private function createTransactionPayloadEncapsulated(string $currency, array $invoiceArray, string $successUrl = null, string $failedUrl = null,$token= null)
    {
        $transactionPayload = new \Wallee\Sdk\Model\TransactionCreate();
        $transactionPayload->setCurrency($currency);
        $transactionPayload->setLineItems($invoiceArray);
        $transactionPayload->setAutoConfirmationEnabled(true);
        if ($successUrl) $transactionPayload->setSuccessUrl($successUrl);
        if ($failedUrl) $transactionPayload->setFailedUrl($failedUrl);
        if ($token) $transactionPayload->setToken($token);

        return $transactionPayload;
    }

    /**
     * Initializes the WALLEE payment link for billing and checkout screen payments.
     *
     * This method prepares and creates a WALLEE transaction for billing and checkout screen payments.
     * It retrieves necessary data such as payload, product information, redirect URL, and currency
     * from the request parameters. Then, it fetches WALLEE API credentials and sets up the API client.
     * Next, it constructs line items based on the product data and sets success and failed URLs for the transaction.
     * Finally, it creates the transaction, generates the payment page URL, and returns it as a JSON response.
     *
     * @return void
     */
    public function initializeWallePaymentLink()
    {

        try {

            $data = $this->request->getPost('payload'); //this can have the payload like : ID
            $products = $this->request->getPost('invoiceData'); //products data
            $redirectUrl = $this->request->getPost('redirectUrl'); //after payment where it will be redirected 
            $currency = $this->request->getPost('currency') ?? 'CHF'; //set the currency
            $id = $data['id'];

            $credentials = $this->getWalleeApiCredentials();
            $spaceId = $credentials['spaceId'];
            $userId = $credentials['userId'];
            $secret = $credentials['secret'];

            // Encode hashKey as base64
            $successBase64HashKey = base64_encode('payment_callback=1&paid_id=' . $id);
            $failedBase64HashKey = base64_encode('payment_callback=0&paid_id=' . $id);

            // Set success and failed URLs
            $successUrl = $redirectUrl . '?' . $successBase64HashKey;
            $failedUrl = $redirectUrl . '?' . $failedBase64HashKey;

            // Setup API client
            $client = new \Wallee\Sdk\ApiClient($userId, $secret);

            // Construct line items
            $invoiceArray = $this->createLineItems($products, 'billing');

            //Initialize Transaction Payload
            $transactionPayload = $this->createTransactionPayloadEncapsulated($currency, $invoiceArray, $successUrl, $failedUrl);

            //Create Transaction on Wallee
            $transaction = $client->getTransactionService()->create($spaceId, $transactionPayload);

            // Create Payment Page URL:
            $redirectionUrl = $client->getTransactionPaymentPageService()->paymentPageUrl($spaceId, $transaction->getId());

            return json_encode($redirectionUrl);

        } catch (\Exception $e) {
            // Return error response
            return json_encode(['error' => 'An error occurred while initializing payment']);
        }
    }

    /**
     * Generates a payment link for web shop checkout.
     *
     * This method constructs a WALLEE transaction for web shop checkout payments.
     * It retrieves necessary data such as success URL, cancel URL, product information, and currency
     * from the payload parameter. Then, it fetches WALLEE API credentials and sets up the API client.
     * Next, it constructs line items based on the product data and sets success and failed URLs for the transaction.
     * Finally, it creates the transaction, generates the payment page URL, and returns it.
     *
     * @param array $payload An array containing payload data including success URL, cancel URL, products, and currency.
     * @return string The payment page URL generated for the web shop checkout.
     */
    function generateLinkForWebshop($payload)
    {

        $orderItems = $payload['payload'];
        $success_url = $payload['success_url'];
        $cancel_url = $payload['cancel_url'];
        $currency = $payload['currency'] ?? 'CHF';

        $credentials = $this->getWalleeApiCredentials();
        $spaceId = $credentials['spaceId'];
        $userId = $credentials['userId'];
        $secret = $credentials['secret'];

        // Setup API client
        $client = new \Wallee\Sdk\ApiClient($userId, $secret);

        // Construct line items
        $invoiceArray = $this->createLineItems($orderItems, 'webshop');

        //initilizing transaction object
        $transactionPayload = $this->createTransactionPayloadEncapsulated($currency, $invoiceArray, $success_url, $cancel_url);

        // Create transaction
        $transaction = $client->getTransactionService()->create($spaceId, $transactionPayload);

        // Create Payment Page URL:
        $redirectionUrl = $client->getTransactionPaymentPageService()->paymentPageUrl($spaceId, $transaction->getId());

        return $redirectionUrl;
    }

    /**
     * Creates a transaction payload for reservation transactions in the billing cashier screen.
     *
     * This method generates a WALLEE transaction payload for reservation transactions in the billing cashier screen.
     * It retrieves necessary data such as amount and customer ID from the request parameters.
     * Then, it fetches WALLEE API credentials and sets up the API client.
     * Next, it constructs a line item for the transaction and sets auto confirmation.
     * After creating the transaction, it triggers the transaction on the terminal.
     * If the transaction state is COMPLETED, FULFILL, or AUTHORIZED, it creates a token and saves it.
     * Finally, it returns a JSON response containing the transaction state.
     *
     * @return string JSON response containing the transaction state.
     */
    public function createTransactionPayload()
    {

        try {
            $amount = $this->request->getPost('amount');
            $customerId = $this->request->getPost('RTR_CUSTOMER_ID') ?? 0;

            $credentials = $this->getWalleeApiCredentials();
            $spaceId = $credentials['spaceId'];
            $userId = $credentials['userId'];
            $secret = $credentials['secret'];
            $terminal = $credentials['terminal'];

            // Setup API client
            $client = new \Wallee\Sdk\ApiClient($userId, $secret);

            // Set line item properties
            $data = [
                'name' => 'Reservation Billing Transaction',
                'price' => number_format($amount, 2),
            ];

            //Creating line item object
            $lineItems = $this->createLineItems([$data], 'cashier');

            //Initialize payload of transaction
            $transactionPayload = $this->createTransactionPayloadEncapsulated('CHF', $lineItems);

            // Create transaction
            $transaction = $client->getTransactionService()->create($spaceId, $transactionPayload);
            $transactionId = $transaction->getId();

            // Trigger transaction on terminal
            $transactionTriggerTerminal = $client->getPaymentTerminalTillService()->performTransactionByIdentifier($spaceId, $transactionId, $terminal);

            if (in_array($transactionTriggerTerminal->getState(), ['COMPLETED', 'FULFILL', 'AUTHORIZED'])) {

                // Create token
                $createdToken = $client->getTokenService()->createTokenWithHttpInfo($spaceId, $transactionId);
                $generatedTokenId = $createdToken->getData()->getId();

                //this will get the token versions details in which we can get the card and the image
                $tokenDetailsByVersions = $client->getTokenVersionService()->activeVersion($spaceId, $generatedTokenId);

                // Access the payment_method_configuration object
                $paymentMethodConfiguration = $tokenDetailsByVersions->getPaymentConnectorConfiguration();

                // Save token in FLXY_CUSTOMER_WALLEE_TOKENS tables
                $this->CustomerWalleeTokensRepository->saveCustomerWalleeTokens($generatedTokenId, $customerId,$tokenDetailsByVersions->getName(),$paymentMethodConfiguration->getImagePath());
            }

            return $this->respond(json_encode($transactionTriggerTerminal->getState()));
        } catch (\Exception $e) {
            // Handle exceptions
            return $this->respond('An error occurred: ' . $e->getMessage());
        }
    }


     /**
     * Create and Process Transaction Based on the customer Token Created Before.
     *
     * This method will create and process a transaction based on the customer Token
     * Amount and Customer ID and the Token will be received from the payload 
     * It will first create the line item and then process the line item in creating transactions
     * and then process the the created transaction with the token
     * 
     * @return string JSON response containing the transaction state.
     */

     public function generateAndProcessTransactionBySavedCustomerToken(){

        try {

            $amount = $this->request->getPost('amount');
            $customerId = $this->request->getPost('RTR_CUSTOMER_ID') ?? 0;
            $TOKEN_ID = $this->request->getPost('TOKEN_ID') ?? 0;

            $credentials = $this->getWalleeApiCredentials();
            $spaceId = $credentials['spaceId'];
            $userId = $credentials['userId'];
            $secret = $credentials['secret'];

            // Setup API client
            $client = new \Wallee\Sdk\ApiClient($userId, $secret);

            // Set line item properties
            $data = [
                'name' => 'Reservation Billing Transaction',
                'price' => number_format($amount, 2),
            ];

            //Creating line item object
            $lineItems = $this->createLineItems([$data], 'cashier');

            //Initialize payload of transaction
            $transactionPayload = $this->createTransactionPayloadEncapsulated('CHF', $lineItems,null,null,$TOKEN_ID);

            // Create transaction
            $transaction = $client->getTransactionService()->create($spaceId, $transactionPayload);
            $transactionId = $transaction->getId();

            //Process Transaction 
            $processTransaction = $client->getTokenService()->processTransaction($spaceId,$transactionId);

            return $this->respond(json_encode($processTransaction->getState()));
        } catch (\Exception $e) {
            // Handle exceptions
            return $this->respond('An error occurred: ' . $e->getMessage());
        }

     }


     /**
     * Save Webhook Payload Sent From Wallee Payment Gateway on Every Transaction.
     *
     * This method saves the webhook payload into FLXY_PAYMENT_MERCHANT_TRANSACTIONS table
     *
     * @return string JSON response containing the transaction state.
     */
    public function walleePaymentGatewayWebhook()
    {
        try {
            // Get the webhook payload
            $payload = $this->request->getVar();

            // Prepare data for database insertion
            $data = [
                'TR_MERCHANT_TYPE' => 'wallee',
                'TR_PAYLOAD' => json_encode($payload)
            ];

            // Store the webhook data in the database
            $success = $this->PaymentTransactionsRepository->createUpdateTransaction($data);
        } catch (\Exception $e) {
            // Return false to indicate failure
            return false;
        }
    }

    /**
     * Get Al Customer Saved Tokens
     *
     * This method gets all the customers saved tokens for the process transaction
     *
     * @return string JSON response containing array of tokens.
     */
    public function allCustomerSavedTokens(){

        $propertyId = defaultProperty();
        $customerId  = $this->request->getPost('customerId');
        $where_condition = "CT_CUST_ID = $customerId AND H_PROPERTY_ID = $propertyId";
        $customerSavedToken = $this->CustomerWalleeTokensRepository->getAllTokens($where_condition);
        return $this->respond(json_encode($customerSavedToken));
    }

    /**
     * Get All Customer Saved Tokens For DataTable
     *
     * This method gets all the customers saved tokens for the in datatable format
     *
     * @return string JSON response containing array of tokens.
     */

    public function getAllCustomerSavedTokensView()
    {
        $customerId = $this->request->getPost('sysid');
        $init_cond = array("H_PROPERTY_ID = " => $this->User_PropertyID);
        $init_cond["CT_CUST_ID = "] = $customerId;
        $mine = new ServerSideDataTable(); 
        $tableName = 'FLXY_CUSTOMER_WALLEE_TOKENS'; 
        $columns = 'CT_ID, CT_TOKEN_ID, CT_CUST_ID, CT_TOKEN_CARD, CT_TOKEN_CARD_IMG, CT_CREATED_AT, CT_UPDATED_AT, H_PROPERTY_ID'; 
        $mine->generate_DatatTable($tableName, $columns, $init_cond);
    }
    
    /**
     * Delete Customer Saved Tokens
     *
     * This method delete customers saved tokens from out db and 
     * from walle as well.
     *
     * @return string JSON response success of deleted.
     */
    public function deleteCustomerSavedTokens(){

        $propertyId = defaultProperty();
        $token = $this->request->getPost('token');

        // echo print_r($token);die();
        //getting all the credentials from environment
        $credentials = $this->getWalleeApiCredentials();
        $spaceId = $credentials['spaceId'];
        $userId = $credentials['userId'];
        $secret = $credentials['secret'];

        // Setup API client
        $client = new \Wallee\Sdk\ApiClient($userId, $secret);
        
        $client->getTokenService()->delete($spaceId,$token);

        $where_condition = "CT_TOKEN_ID = $token AND H_PROPERTY_ID = $propertyId";
        $deleteToken = $this->CustomerWalleeTokensRepository->destroyToken($where_condition);
        return $this->respond(json_encode($deleteToken));
    }

     /**
     * Initializes the WALLEE payment link for GUEST APP API;s.
     *
     * This method prepares and creates a WALLEE transaction for guest app API's.
     * It retrieves necessary data such as payload, product information, redirect URL, and currency
     * from the request parameters. Then, it fetches WALLEE API credentials and sets up the API client.
     * Next, it constructs line items based on the product data and sets success and failed URLs for the transaction.
     * Finally, it creates the transaction, generates the payment page URL, and returns it as a JSON response.
     *
     * @return void
     */
    public function initializeWallePaymentLinkGuestAppApi($payload,$success_url,$cancel_url,$currency)
    {
        try {
            $credentials = $this->getWalleeApiCredentials();
            $spaceId = $credentials['spaceId'];
            $userId = $credentials['userId'];
            $secret = $credentials['secret'];

            $successUrl = $success_url;
            $failedUrl = $cancel_url;

            // Setup API client
            $client = new \Wallee\Sdk\ApiClient($userId, $secret);

            // Construct line items
            $invoiceArray = $this->createLineItems($payload, 'guest-app');
            //Initialize Transaction Payload
            $transactionPayload = $this->createTransactionPayloadEncapsulated($currency, $invoiceArray, $successUrl, $failedUrl);
            //Create Transaction on Wallee
            $transaction = $client->getTransactionService()->create($spaceId, $transactionPayload);
            
            // Create Payment Page URL:
            $redirectionUrl = $client->getTransactionPaymentPageService()->paymentPageUrl($spaceId, $transaction->getId());

            // return $redirectionUrl;
            return responseJson(200, false, ['msg' => 'payment link generated successfully.'], $redirectionUrl);


        } catch (\Exception $e) {
            // Return error response
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}