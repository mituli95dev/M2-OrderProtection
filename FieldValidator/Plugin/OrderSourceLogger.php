<?php
namespace Abc\FieldValidator\Plugin;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\InputException;

class OrderSourceLogger
{
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function getClientIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    public function beforeSave(OrderRepositoryInterface $subject, $order)
    {
        // Order-specific logic here...
        $isApiOrder = false;

        // Check if the order is placed via API by inspecting the current request
        if (php_sapi_name() !== 'cli' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            if (strpos($userAgent, 'REST') !== false || strpos($userAgent, 'API') !== false) {
                $isApiOrder = true;
            }
            // Check for Postman or curl user agents
            if (stripos($userAgent, 'Postman') !== false || stripos($userAgent, 'curl') !== false) {
                throw new InputException(__("Order processing is not allowed for requests made using Postman or curl."));
            }
        }

        try {
            // Validate firstname and lastname with length limit
            $this->validateInput($order->getCustomerFirstname(), 'First Name', 90);
            $this->validateInput($order->getCustomerLastname(), 'Last Name', 90);

            // Assuming there is a field for order comments
            if ($order->getCustomerNote()) {
                $this->validateInput($order->getCustomerNote(), 'Order Comments');
            }

            // Log the source of the order and additional request details
            $orderSource = $isApiOrder ? 'API' : 'Web';
            $this->logger->info('Order placed via ' . $orderSource . ': Order email ' . $order->getCustomerEmail());

            $this->logger->info('Order Details', [
                'IP' => $this->getClientIp(),
                'User Agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User Agent',
                'Request URI' => $_SERVER['REQUEST_URI'] ?? 'Unknown URI',
                'Customer Email' => $order->getCustomerEmail() ?? 'Unknown Email',
            ]);
	    
        } catch (InputException $e) {
            // Log the unsuccessful attempt
            $this->logger->warning('Unsuccessful order attempt: ' . $e->getMessage(), [
                'IP' => $this->getClientIp(),
                'User Agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User Agent',
                'Request URI' => $_SERVER['REQUEST_URI'] ?? 'Unknown URI',
                'Customer Email' => $order->getCustomerEmail() ?? 'Unknown Email',
            ]);
            // Rethrow the exception to stop the order from being saved
            throw $e;
        }
    }

	private function validateInput($input, $fieldName, $maxLength = null)
    {
        if (empty($input)) {
            return;
        }

        // Add any disallowed characters here
        if (preg_match('/[{}<>%]/', $input)) {
            throw new InputException(__("Invalid characters in $fieldName."));
        }

        if ($maxLength !== null && strlen($input) > $maxLength) {
            throw new InputException(__("$fieldName cannot exceed $maxLength characters."));
        }
    }
}