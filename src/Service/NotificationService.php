<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;

/**
 * Handles payment notifications via email and logging.
 */
class NotificationService {

  use StringTranslationTrait;

  /**
   * Mail key for successful payments.
   */
  private const MAIL_KEY_SUCCESS = 'payment_success';

  /**
   * Mail key for failed payments.
   */
  private const MAIL_KEY_FAILURE = 'payment_failure';

  /**
   * Module name for mail sending.
   */
  private const MODULE_NAME = 'webform_securepay';

  /**
   * Constructs a NotificationService.
   *
   * @param \Drupal\webform_securepay\Service\ConfigurationService $configService
   *   The configuration service.
   * @param \Drupal\Core\Mail\MailManagerInterface|null $mailManager
   *   The mail manager service (optional for simplified operation).
   * @param \Drupal\Core\Session\AccountInterface|null $currentUser
   *   The current user service (optional).
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly ?MailManagerInterface $mailManager,
    private readonly ?AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sends a payment notification based on the result.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param array $context
   *   Additional context information.
   */
  public function sendPaymentNotification(PaymentResult $result, array $context = []): void {
    if (!$this->isEmailNotificationsEnabled()) {
      return;
    }

    $notificationEmail = $this->getNotificationEmail();
    if (empty($notificationEmail)) {
      $this->logger->warning('Email notifications enabled but no notification email configured');
      return;
    }

    try {
      if ($result->success) {
        $this->sendSuccessNotification($result, $notificationEmail, $context);
      } else {
        $this->sendFailureNotification($result, $notificationEmail, $context);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send payment notification: {message}', [
        'message' => $e->getMessage(),
        'transaction_id' => $result->transactionId,
        'email' => $notificationEmail,
      ]);
    }
  }

  /**
   * Logs a payment event for administrative tracking.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param array $context
   *   Additional context information.
   */
  public function logPaymentEvent(PaymentResult $result, array $context = []): void {
    $logContext = [
      'transaction_id' => $result->transactionId,
      'status' => $result->status,
      'amount' => $result->amount,
      'currency' => $result->currency,
    ];

    // Add additional context
    if (!empty($context)) {
      $logContext = array_merge($logContext, $context);
    }

    if ($result->success) {
      $this->logger->info('Payment processed successfully: {transaction_id} - {currency} {amount}', $logContext);
    } else {
      $this->logger->warning('Payment failed: {transaction_id} - {error}', array_merge($logContext, [
        'error' => $result->error ?? 'Unknown error',
        'error_code' => $result->errorCode,
      ]));
    }
  }

  /**
   * Sends a high-priority alert for critical payment issues.
   *
   * @param string $subject
   *   Alert subject.
   * @param string $message
   *   Alert message.
   * @param array $context
   *   Additional context.
   */
  public function sendAlert(string $subject, string $message, array $context = []): void {
    $this->logger->critical('Payment Alert: {subject} - {message}', [
      'subject' => $subject,
      'message' => $message,
    ] + $context);

    // In a full implementation, this could send SMS, Slack notifications, etc.
    if ($this->isEmailNotificationsEnabled()) {
      $notificationEmail = $this->getNotificationEmail();
      if (!empty($notificationEmail)) {
        // Log that an alert would be sent
        $this->logger->info('Alert notification would be sent to: {email}', [
          'email' => $notificationEmail,
          'subject' => $subject,
        ]);
      }
    }
  }

  /**
   * Gets notification configuration for status checks.
   *
   * @return array
   *   Notification configuration.
   */
  public function getConfiguration(): array {
    return [
      'email_notifications_enabled' => $this->isEmailNotificationsEnabled(),
      'notification_email' => $this->getNotificationEmail(),
      'has_mail_manager' => $this->mailManager !== null,
    ];
  }

  /**
   * Sends a success notification email.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param string $to
   *   Recipient email address.
   * @param array $context
   *   Additional context.
   */
  private function sendSuccessNotification(PaymentResult $result, string $to, array $context): void {
    if (!$this->mailManager) {
      $this->logger->info('Success notification would be sent to: {email}', [
        'email' => $to,
        'transaction_id' => $result->transactionId,
      ]);
      return;
    }

    $params = [
      'result' => $result,
      'context' => $context,
      'subject' => $this->t('Payment Successful - Transaction @id', [
        '@id' => $result->transactionId,
      ]),
      'body' => $this->buildSuccessEmailBody($result, $context),
    ];

    $this->sendMail(self::MAIL_KEY_SUCCESS, $to, $params);
  }

  /**
   * Sends a failure notification email.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param string $to
   *   Recipient email address.
   * @param array $context
   *   Additional context.
   */
  private function sendFailureNotification(PaymentResult $result, string $to, array $context): void {
    if (!$this->mailManager) {
      $this->logger->info('Failure notification would be sent to: {email}', [
        'email' => $to,
        'transaction_id' => $result->transactionId,
        'error' => $result->error,
      ]);
      return;
    }

    $params = [
      'result' => $result,
      'context' => $context,
      'subject' => $this->t('Payment Failed - Transaction @id', [
        '@id' => $result->transactionId ?: 'Unknown',
      ]),
      'body' => $this->buildFailureEmailBody($result, $context),
    ];

    $this->sendMail(self::MAIL_KEY_FAILURE, $to, $params);
  }

  /**
   * Builds the email body for successful payments.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param array $context
   *   Additional context.
   *
   * @return array
   *   Email body as array of lines.
   */
  private function buildSuccessEmailBody(PaymentResult $result, array $context): array {
    $body = [];
    
    $body[] = $this->t('A payment has been successfully processed through SecurePay.');
    $body[] = '';
    $body[] = $this->t('Transaction Details:');
    $body[] = $this->t('- Transaction ID: @id', ['@id' => $result->transactionId]);
    $body[] = $this->t('- Amount: @currency @amount', [
      '@currency' => $result->currency,
      '@amount' => number_format($result->amount / 100, 2),
    ]);
    $body[] = $this->t('- Status: @status', ['@status' => $result->status]);
    
    if ($result->bankTransactionId) {
      $body[] = $this->t('- Bank Transaction ID: @id', ['@id' => $result->bankTransactionId]);
    }
    
    if ($result->gatewayResponseCode) {
      $body[] = $this->t('- Gateway Response Code: @code', ['@code' => $result->gatewayResponseCode]);
    }
    
    // Add context information if available
    if (!empty($context['webform_title'])) {
      $body[] = '';
      $body[] = $this->t('Webform: @title', ['@title' => $context['webform_title']]);
    }
    
    if (!empty($context['submission_id'])) {
      $body[] = $this->t('Submission ID: @id', ['@id' => $context['submission_id']]);
    }
    
    $body[] = '';
    $body[] = $this->t('Processed at: @time', ['@time' => date('Y-m-d H:i:s')]);
    
    return $body;
  }

  /**
   * Builds the email body for failed payments.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param array $context
   *   Additional context.
   *
   * @return array
   *   Email body as array of lines.
   */
  private function buildFailureEmailBody(PaymentResult $result, array $context): array {
    $body = [];
    
    $body[] = $this->t('A payment attempt has failed through SecurePay.');
    $body[] = '';
    $body[] = $this->t('Error Details:');
    $body[] = $this->t('- Error: @error', ['@error' => $result->error ?? 'Unknown error']);
    
    if ($result->errorCode) {
      $body[] = $this->t('- Error Code: @code', ['@code' => $result->errorCode]);
    }
    
    if ($result->transactionId) {
      $body[] = $this->t('- Transaction ID: @id', ['@id' => $result->transactionId]);
    }
    
    $body[] = $this->t('- Attempted Amount: @currency @amount', [
      '@currency' => $result->currency,
      '@amount' => number_format($result->amount / 100, 2),
    ]);
    
    if ($result->gatewayResponseCode) {
      $body[] = $this->t('- Gateway Response Code: @code', ['@code' => $result->gatewayResponseCode]);
    }
    
    if ($result->gatewayResponseMessage) {
      $body[] = $this->t('- Gateway Message: @message', ['@message' => $result->gatewayResponseMessage]);
    }
    
    // Add context information if available
    if (!empty($context['webform_title'])) {
      $body[] = '';
      $body[] = $this->t('Webform: @title', ['@title' => $context['webform_title']]);
    }
    
    if (!empty($context['ip_address'])) {
      $body[] = $this->t('IP Address: @ip', ['@ip' => $context['ip_address']]);
    }
    
    $body[] = '';
    $body[] = $this->t('Failed at: @time', ['@time' => date('Y-m-d H:i:s')]);
    
    return $body;
  }

  /**
   * Sends an email using the mail manager.
   *
   * @param string $key
   *   Mail template key.
   * @param string $to
   *   Recipient email address.
   * @param array $params
   *   Mail parameters.
   */
  private function sendMail(string $key, string $to, array $params): void {
    try {
      $langcode = $this->currentUser?->getPreferredLangcode() ?? 'en';
      
      $result = $this->mailManager->mail(
        self::MODULE_NAME,
        $key,
        $to,
        $langcode,
        $params,
        null,
        true
      );
      
      if ($result['result']) {
        $this->logger->info('Payment notification sent successfully to: {email}', [
          'email' => $to,
          'key' => $key,
        ]);
      } else {
        $this->logger->error('Failed to send payment notification to: {email}', [
          'email' => $to,
          'key' => $key,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Mail sending exception: {message}', [
        'message' => $e->getMessage(),
        'email' => $to,
        'key' => $key,
      ]);
    }
  }

  /**
   * Checks if email notifications are enabled.
   *
   * @return bool
   *   TRUE if email notifications are enabled.
   */
  private function isEmailNotificationsEnabled(): bool {
    return (bool) $this->configService->get('email_notifications', false);
  }

  /**
   * Gets the configured notification email address.
   *
   * @return string
   *   Notification email address or empty string.
   */
  private function getNotificationEmail(): string {
    return (string) $this->configService->get('notification_email', '');
  }
}