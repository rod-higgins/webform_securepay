<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;

/**
 * Improved notification service with proper dependency handling.
 */
class NotificationService {

  use StringTranslationTrait;

  // Mail template keys
  private const MAIL_KEY_SUCCESS = 'payment_success';
  private const MAIL_KEY_FAILURE = 'payment_failure';
  private const MAIL_KEY_ALERT = 'payment_alert';
  private const MODULE_NAME = 'webform_securepay';

  // Email subject limits
  private const MAX_SUBJECT_LENGTH = 200;
  private const MAX_BODY_LINES = 50;

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly MailManagerInterface $mailManager,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Send payment notification based on result.
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
   * Log payment event for administrative tracking.
   */
  public function logPaymentEvent(PaymentResult $result, array $context = []): void {
    $logContext = [
      'transaction_id' => $result->transactionId,
      'status' => $result->status,
      'amount' => $result->amount,
      'currency' => $result->currency,
    ];

    // Merge additional context
    $logContext = array_merge($logContext, $this->sanitizeContext($context));

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
   * Send high-priority alert for critical payment issues.
   */
  public function sendAlert(string $subject, string $message, array $context = []): void {
    // Truncate subject if too long
    $subject = $this->truncateString($subject, self::MAX_SUBJECT_LENGTH);
    
    $this->logger->critical('Payment Alert: {subject} - {message}', [
      'subject' => $subject,
      'message' => $message,
    ] + $this->sanitizeContext($context));

    if ($this->isEmailNotificationsEnabled()) {
      $notificationEmail = $this->getNotificationEmail();
      if (!empty($notificationEmail)) {
        $this->sendAlertEmail($subject, $message, $notificationEmail, $context);
      }
    }
  }

  /**
   * Get notification configuration for status checks.
   */
  public function getConfiguration(): array {
    return [
      'email_notifications_enabled' => $this->isEmailNotificationsEnabled(),
      'notification_email' => $this->getNotificationEmail(),
      'mail_system_available' => true,
    ];
  }

  /**
   * Send success notification email.
   */
  private function sendSuccessNotification(PaymentResult $result, string $to, array $context): void {
    $subject = $this->t('Payment Successful - Transaction @id', [
      '@id' => $result->transactionId,
    ]);

    $params = [
      'result' => $result,
      'context' => $context,
      'subject' => $this->truncateString((string) $subject, self::MAX_SUBJECT_LENGTH),
      'body' => $this->buildSuccessEmailBody($result, $context),
    ];

    $this->sendMail(self::MAIL_KEY_SUCCESS, $to, $params);
  }

  /**
   * Send failure notification email.
   */
  private function sendFailureNotification(PaymentResult $result, string $to, array $context): void {
    $subject = $this->t('Payment Failed - Transaction @id', [
      '@id' => $result->transactionId ?: 'Unknown',
    ]);

    $params = [
      'result' => $result,
      'context' => $context,
      'subject' => $this->truncateString((string) $subject, self::MAX_SUBJECT_LENGTH),
      'body' => $this->buildFailureEmailBody($result, $context),
    ];

    $this->sendMail(self::MAIL_KEY_FAILURE, $to, $params);
  }

  /**
   * Send alert email.
   */
  private function sendAlertEmail(string $subject, string $message, string $to, array $context): void {
    $params = [
      'subject' => '[ALERT] ' . $subject,
      'body' => [
        $this->t('SecurePay Payment System Alert'),
        '',
        $this->t('Alert: @subject', ['@subject' => $subject]),
        $this->t('Message: @message', ['@message' => $message]),
        '',
        $this->t('Time: @time', ['@time' => date('Y-m-d H:i:s')]),
      ],
      'context' => $context,
    ];

    $this->sendMail(self::MAIL_KEY_ALERT, $to, $params);
  }

  /**
   * Build success email body.
   */
  private function buildSuccessEmailBody(PaymentResult $result, array $context): array {
    $body = [
      $this->t('A payment has been successfully processed through SecurePay.'),
      '',
      $this->t('Transaction Details:'),
      $this->t('- Transaction ID: @id', ['@id' => $result->transactionId]),
      $this->t('- Amount: @currency @amount', [
        '@currency' => $result->currency,
        '@amount' => number_format($result->amount / 100, 2),
      ]),
      $this->t('- Status: @status', ['@status' => $result->status]),
    ];
    
    $this->addOptionalFields($body, $result, $context);
    $this->addTimestamp($body);
    
    return array_slice($body, 0, self::MAX_BODY_LINES);
  }

  /**
   * Build failure email body.
   */
  private function buildFailureEmailBody(PaymentResult $result, array $context): array {
    $body = [
      $this->t('A payment attempt has failed through SecurePay.'),
      '',
      $this->t('Error Details:'),
      $this->t('- Error: @error', ['@error' => $result->error ?? 'Unknown error']),
    ];

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

    $this->addOptionalFields($body, $result, $context);
    $this->addTimestamp($body);
    
    return array_slice($body, 0, self::MAX_BODY_LINES);
  }

  /**
   * Add optional fields to email body.
   */
  private function addOptionalFields(array &$body, PaymentResult $result, array $context): void {
    if ($result->bankTransactionId) {
      $body[] = $this->t('- Bank Transaction ID: @id', ['@id' => $result->bankTransactionId]);
    }
    
    if ($result->gatewayResponseCode) {
      $body[] = $this->t('- Gateway Response: @code', ['@code' => $result->gatewayResponseCode]);
    }

    if (!empty($context['webform_title'])) {
      $body[] = '';
      $body[] = $this->t('Webform: @title', ['@title' => $context['webform_title']]);
    }
    
    if (!empty($context['ip_address'])) {
      $body[] = $this->t('IP Address: @ip', ['@ip' => $context['ip_address']]);
    }
  }

  /**
   * Add timestamp to email body.
   */
  private function addTimestamp(array &$body): void {
    $body[] = '';
    $body[] = $this->t('Processed at: @time', ['@time' => date('Y-m-d H:i:s')]);
  }

  /**
   * Send email using mail manager.
   */
  private function sendMail(string $key, string $to, array $params): void {
    try {
      $langcode = $this->currentUser->getPreferredLangcode();
      
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
   * Check if email notifications are enabled.
   */
  private function isEmailNotificationsEnabled(): bool {
    return (bool) $this->configService->get(ConfigurationService::EMAIL_NOTIFICATIONS, false);
  }

  /**
   * Get notification email address.
   */
  private function getNotificationEmail(): string {
    return (string) $this->configService->get(ConfigurationService::NOTIFICATION_EMAIL, '');
  }

  /**
   * Sanitize context data for logging.
   */
  private function sanitizeContext(array $context): array {
    // Remove sensitive data
    $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
    
    foreach ($sensitiveKeys as $key) {
      if (isset($context[$key])) {
        $context[$key] = '[REDACTED]';
      }
    }
    
    // Limit array depth and size
    return array_slice($context, 0, 10);
  }

  /**
   * Truncate string to specified length.
   */
  private function truncateString(string $string, int $maxLength): string {
    if (strlen($string) <= $maxLength) {
      return $string;
    }
    
    return substr($string, 0, $maxLength - 3) . '...';
  }
}