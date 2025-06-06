<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for SecurePay API interactions.
 */
class SecurePayApiService {

  use StringTranslationTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a SecurePayApiService object.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('webform_securepay');
    $this->messenger = $messenger;
  }

  /**
   * Process a payment through SecurePay.
   *
   * @param array $payment_data
   *   The payment data array.
   * @param array $element_settings
   *   The element settings.
   *
   * @return array
   *   The payment result.
   */
  public function processPayment(array $payment_data, array $element_settings = []) {
    $config = $this->configFactory->get('webform_securepay.settings');
    
    // Merge element settings with global config
    $settings = $this->mergeSettings($element_settings, $config);
    
    // Validate required settings
    if (empty($settings['merchant_id']) || empty($settings['password'])) {
      $this->logger->error('SecurePay merchant credentials not configured');
      return [
        'success' => FALSE,
        'error' => $this->t('Payment configuration error'),
      ];
    }

    // Generate unique order ID
    $order_id = $this->generateOrderId($settings['order_id_prefix'] ?? 'WF_');
    
    // Build XML request
    $xml_request = $this->buildXmlRequest($payment_data, $settings, $order_id);
    
    try {
      $response = $this->httpClient->post($settings['api_url'], [
        'body' => $xml_request,
        'headers' => [
          'Content-Type' => 'text/xml',
          'SOAPAction' => '',
        ],
        'timeout' => $settings['timeout'] ?? 30,
      ]);

      $response_body = $response->getBody()->getContents();
      $result = $this->parseXmlResponse($response_body);
      
      // Log transaction if enabled
      if ($config->get('log_transactions')) {
        $this->logger->info('SecurePay transaction: @order_id - @status', [
          '@order_id' => $order_id,
          '@status' => $result['status'] ?? 'unknown',
        ]);
      }
      
      return $result;
      
    } catch (RequestException $e) {
      $this->logger->error('SecurePay API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'success' => FALSE,
        'error' => $this->t('Payment processing failed. Please try again.'),
      ];
    }
  }

  /**
   * Build XML request for SecurePay API.
   */
  protected function buildXmlRequest(array $payment_data, array $settings, $order_id) {
    $amount = $payment_data['amount'];
    $currency = $settings['currency'] ?? 'AUD';
    
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<SecurePayMessage>';
    $xml .= '<MessageInfo>';
    $xml .= '<messageID>' . uniqid() . '</messageID>';
    $xml .= '<messageTimestamp>' . date('YmdHis000000+600') . '</messageTimestamp>';
    $xml .= '<timeoutValue>60</timeoutValue>';
    $xml .= '<apiVersion>xml-4.2</apiVersion>';
    $xml .= '</MessageInfo>';
    
    $xml .= '<MerchantInfo>';
    $xml .= '<merchantID>' . htmlspecialchars($settings['merchant_id']) . '</merchantID>';
    $xml .= '<password>' . htmlspecialchars($settings['password']) . '</password>';
    $xml .= '</MerchantInfo>';
    
    $xml .= '<RequestType>Payment</RequestType>';
    $xml .= '<Payment>';
    $xml .= '<TxnList count="1">';
    $xml .= '<Txn ID="1">';
    $xml .= '<txnType>0</txnType>'; // Standard payment
    $xml .= '<txnSource>23</txnSource>'; // Internet
    $xml .= '<amount>' . $amount . '</amount>';
    $xml .= '<currency>' . $currency . '</currency>';
    $xml .= '<purchaseOrderNo>' . htmlspecialchars($order_id) . '</purchaseOrderNo>';
    
    // Credit card details (if provided)
    if (!empty($payment_data['card_number'])) {
      $xml .= '<CreditCardInfo>';
      $xml .= '<cardNumber>' . htmlspecialchars($payment_data['card_number']) . '</cardNumber>';
      $xml .= '<expiryDate>' . htmlspecialchars($payment_data['expiry_date']) . '</expiryDate>';
      if (!empty($payment_data['cvv'])) {
        $xml .= '<cvv>' . htmlspecialchars($payment_data['cvv']) . '</cvv>';
      }
      $xml .= '</CreditCardInfo>';
    }
    
    $xml .= '</Txn>';
    $xml .= '</TxnList>';
    $xml .= '</Payment>';
    $xml .= '</SecurePayMessage>';
    
    return $xml;
  }

  /**
   * Parse XML response from SecurePay.
   */
  protected function parseXmlResponse($xml_response) {
    try {
      $xml = simplexml_load_string($xml_response);
      
      if ($xml === FALSE) {
        throw new \Exception('Invalid XML response');
      }
      
      $status = (string) $xml->Status->statusCode;
      $description = (string) $xml->Status->statusDescription;
      
      $result = [
        'success' => $status === '000',
        'status' => $status,
        'description' => $description,
        'raw_response' => $xml_response,
      ];
      
      // Extract transaction details if available
      if (isset($xml->Payment->TxnList->Txn)) {
        $txn = $xml->Payment->TxnList->Txn;
        $result['transaction_id'] = (string) $txn->txnID;
        $result['amount'] = (string) $txn->amount;
        $result['order_id'] = (string) $txn->purchaseOrderNo;
        $result['settlement_date'] = (string) $txn->settlementDate;
      }
      
      return $result;
      
    } catch (\Exception $e) {
      $this->logger->error('Failed to parse SecurePay response: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'success' => FALSE,
        'error' => $this->t('Invalid payment response'),
      ];
    }
  }

  /**
   * Generate unique order ID.
   */
  protected function generateOrderId($prefix = 'WF_') {
    return $prefix . time() . '_' . substr(uniqid(), -6);
  }

  /**
   * Merge element settings with global configuration.
   */
  protected function mergeSettings(array $element_settings, $config) {
    $settings = [];
    
    // Get from element settings first, then fallback to global config
    $keys = ['merchant_id', 'password', 'api_url', 'test_mode', 'currency', 'timeout', 'order_id_prefix'];
    
    foreach ($keys as $key) {
      $settings[$key] = $element_settings[$key] ?? $config->get($key);
    }
    
    return $settings;
  }

}