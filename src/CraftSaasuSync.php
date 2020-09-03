<?php
/**
 * Craft Saasu Sync plugin for Craft CMS 3.x
 * Syncs the Craft Commerce products with Saasu.
 * @link      https://www.headjam.com.au
 * @copyright Copyright (c) 2020 Ben Norman
 */

namespace headjam\craftsaasusync;

use Craft;
use craft\base\Plugin;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\events\CustomizeVariantSnapshotFieldsEvent;
use craft\elements\Entry;
use craft\events\ModelEvent;

use yii\base\Event;

/**
 *
 * @author    Ben Norman
 * @package   CraftSaasuSync
 * @since     1.0.0
 *
 */
class CraftSaasuSync extends Plugin
{
  // Static Properties
  // =========================================================================
  /**
   * Static property that is an instance of this plugin class so that it can be accessed via
   * CraftSaasuSync::$plugin
   * @var CraftSaasuSync
   */
  public static $plugin;



  // Public Properties
  // =========================================================================
  /**
   * To execute your plugin’s migrations, you’ll need to increase its schema version.
   * @var string
   */
  public $schemaVersion = '1.0.0';

  /**
   * Set to `true` if the plugin should have a settings view in the control panel.
   * @var bool
   */
  public $hasCpSettings = false;

  /**
   * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
   * @var bool
   */
  public $hasCpSection = false;



  // Private Properties
  // =========================================================================
  private $saasuApiUrl = 'https://api.saasu.com/';
  private $saasuAuth;
  private $saasuBankAccount;
  private $saasuItemAccount;
  private $saasuServiceAccount;
  private $saasuShippingId;



  // Public Methods
  // =========================================================================
  /**
   * A customer logger for the plugin.
   */
  public static function log($message){
    Craft::getLogger()->log($message, \yii\log\Logger::LEVEL_INFO, 'craft-saasu-sync');
  }
  /**
   * Set our $plugin static property to this class so that it can be accessed via
   * CraftSaasuSync::$plugin
   *
   * Called after the plugin class is instantiated; do any one-time initialization
   * here such as hooks and events.
   * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
   * you do not need to load it in your init() method.
   *
   */
  public function init()
  {
    parent::init();
    self::$plugin = $this;

    // Init the customer logger
    $fileTarget = new \craft\log\FileTarget([
      'logFile' => Craft::getAlias('@storage/logs/craftSaasuSync.log'),
      'categories' => ['craft-saasu-sync']
    ]);
    Craft::getLogger()->dispatcher->targets[] = $fileTarget;

    // Set auth
    $this->saasuAuth = '?WsAccessKey=' . getenv('SAASU_KEY') . '&FileId=' . getenv('SAASU_FILE_ID');

    // Set accounts to use
    $this->saasuBankAccount = getenv('SAASU_BANK_ACCOUNT');
    $this->saasuItemAccount = getenv('SAASU_ITEM_ACCOUNT');
    $this->saasuServiceAccount = getenv('SAASU_SERVICE_ACCOUNT');
    $this->saasuShippingId = getenv('SAASU_SHIPPING_ID');

    // On variant save event,
    // sync product levels with Saasu
    Event::on(
      Variant::class,
      Variant::EVENT_BEFORE_SAVE,
      function (ModelEvent $e) {
        CraftSaasuSync::syncProductStockLevels($e->sender);
      }
    );

    // On snapshot save event, add the
    // SaasuId to the snapshot
    Event::on(
      Variant::class,
      Variant::EVENT_BEFORE_CAPTURE_VARIANT_SNAPSHOT,
      function(CustomizeVariantSnapshotFieldsEvent $event) {
        $variant = $event->variant;
        $fields = $event->fields;
        $fields[] = 'saasuId';
        $event->fields = $fields;
      }
    );

    // On order complete event,
    // adjust product levels on Saasu
    Event::on(
      Order::class,
      Order::EVENT_AFTER_COMPLETE_ORDER,
      function(Event $e) {
        $orderId = $e->sender->id;
        $order = Order::find()->id($orderId)->one();
        if ($orderId && $order) {
          CraftSaasuSync::generateAndPostInvoices($order);
        }
      }
    );
  }

  // Protected Methods
  // =========================================================================
  /** 
   * Returns true if all the required fields for Saasu are supplied.
   * @return Boolean
   */
  private function saasuIntegrationValid() {
    return $this->saasuAuth && $this->saasuBankAccount && $this->saasuItemAccount && $this->saasuServiceAccount && $this->saasuShippingId;
  }

  /**
   * Generate the Saaus-ready item adjustments for the given line items.
   * @param Order $order - The order to generate line items from.
   * @return Object[] - The Saasu-ready line items and amounts.
   */
  private function generateLineItems($order) {
    $items = [];
    $saasuAmount = 0;
    $unlistedAmount = 0;
    foreach ($order->lineItems as $lineItem) {
      if ($lineItem->snapshot) {
        if ($lineItem->snapshot['saasuId']) {
          $item = array(
            'Description' => $lineItem->snapshot['title'],
            'AccountId' => $this->saasuItemAccount,
            'Quantity' => $lineItem->qty,
            'UnitPrice' => $lineItem->salePrice,
            'InventoryId' => $lineItem->snapshot['saasuId'],
          );
          $saasuAmount += $lineItem->snapshot['price'] * $lineItem->qty;
          $items[] = $item;
        } else {
          $unlistedAmount += $lineItem->snapshot['price'] * $lineItem->qty;
        }
      }
    }
    if ($order->storedTotalShippingCost > 0 && $this->saasuShippingId) {
      $item = array(
        'Description' => 'Shipping fee',
        'AccountId' => $this->saasuItemAccount,
        'Quantity' => 1,
        'UnitPrice' => $order->storedTotalShippingCost,
        'InventoryId' => $this->saasuShippingId,
      );
      $saasuAmount += $order->storedTotalShippingCost;
      $items[] = $item;
    }
    return array(
      'items' => $items,
      'saasuAmount' => $saasuAmount,
      'unlistedAmount' => $unlistedAmount,
    );
  }

  /**
   * Generate the line item for shipping.
   * @param Number $amount - The unlisted cost amount.
   */
  private function generateUnlistedLineItem($amount) {
    return array(
      'AccountId' => $this->saasuServiceAccount,
      'Description' => 'Unlisted items in online order',
      'TotalAmount' => $amount,
    );
  }

  // OBSOLETE - This function was previously used when the Shipping item was a service charge.
  // /**
  //  * Generate the line item for shipping.
  //  * @param Order $order - The order that was submitted.
  //  */
  // private function generateShippingLineItem($order) {
  //   return array(
  //     'AccountId' => $this->saasuServiceAccount,
  //     'Description' => 'Shipping fee for online order',
  //     'TotalAmount' => $order->storedTotalShippingCost,
  //   );
  // }

  /**
   * Generate the Saaus-ready payment details for the given order.
   * @param Order $order - The Craft order to generate payment details for.
   * @param String $amount - The amount to generate a payment for.
   * @param String $desc - The payment description.
   * @return Object - The Saasu-ready payment details.
   */
  private function generatePaymentDetails($order, $amount, $desc) {
    return array(
      'DatePaid' => $order->datePaid->format('c'),
      'BankedToAccountId' => $this->saasuBankAccount,
      'Amount' => $amount,
      'Reference' => $desc . '(Craft reference: ' . $order->number . ')',
    );
  }

  /**
   * Perpare the invoice data to send to the Saasu api.
   * @param Order $order - The order that was submitted.
   * @param Boolean $service - True if this is a service invoice, else false.
   * @param LineItem[] $lineItems - The array of line items.
   * @param InvoiceQuickPaymentDetail|null $payment - The optional payment made by the user.
   * @param String $desc - The description text to add to the notes.
   * @param String|null $po - The purchase order number.
   */
  private function generateInvoiceData($order, $service, $lineItems, $payment, $desc, $po) {
    $invoice = array(
      'LineItems' => $lineItems,
      'NotesInternal' => $desc . ' - Online order reference: ' . $order->number,
      'InvoiceNumber' => '<Auto Number>',
      'InvoiceType' => 'Sale Order',
      'TransactionType' => 'S',
      'Layout' => $service ? 'S' : 'I',
      'Summary' => $desc . ' - Online order reference: ' . $order->number,
      'IsTaxInc' => true,
      'RequiresFollowUp' => false,
      'TransactionDate' => $order->dateOrdered->format('c'),
    );
    if ($payment) {
      $invoice['QuickPayment'] = $payment;
      $invoice['Currency'] = $order->currency;
    }
    if ($po) {
      // $invoice['InvoiceType'] = 'Purchase Order';
      $invoice['PurchaseOrderNumber'] = $po;
    }
    return $invoice;
  }

  /**
   * Send the invoice to the api.
   * @param Object $data - The Saasu-ready invoice.
   */
  private function postInvoice($data) {
    $url = $this->saasuApiUrl . 'Invoice' . $this->saasuAuth;
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $curl_response = curl_exec($curl);
    if ($curl_response === false) {
      $info = curl_getinfo($curl);
      CraftSaasuSync::log('postInvoice() -> Error occured during curl exec.');
    }
    curl_close($curl);
  }

  /**
   * Prepare and send invoices to Saasu
   * for products and shipping (if applicable).
   */
  private function generateAndPostInvoices($order) {
    if (CraftSaasuSync::saasuIntegrationValid()) {
      $itemsDesc = 'Listed items';
      $itemsAndAmounts = CraftSaasuSync::generateLineItems($order);
      $itemsPayment = $order->datePaid ? CraftSaasuSync::generatePaymentDetails($order, $itemsAndAmounts['saasuAmount'], $itemsDesc) : null;
      $itemsPo = $order->gateway->handle == 'purchaseOrder' ? $order->orderReference : null;
      $itemsInvoice = CraftSaasuSync::generateInvoiceData($order, false, $itemsAndAmounts['items'], $itemsPayment, $itemsDesc, $itemsPo);
      CraftSaasuSync::postInvoice($itemsInvoice);

      if ($itemsAndAmounts['unlistedAmount'] > 0) {
        $unlistedItemsDesc = 'Unlisted items';
        $unlistedItems = CraftSaasuSync::generateUnlistedLineItem($itemsAndAmounts['unlistedAmount']);
        $unlistedItemsPayment = $order->datePaid ? CraftSaasuSync::generatePaymentDetails($order, $itemsAndAmounts['unlistedAmount'], $unlistedItemsDesc) : null;
        $unlistedItemsInvoice = CraftSaasuSync::generateInvoiceData($order, true, [$unlistedItems], $unlistedItemsPayment, $unlistedItemsDesc, $itemsPo);
        CraftSaasuSync::postInvoice($unlistedItemsInvoice);
      }
    }
  }

  /**
   * If the given variant has a SaasuId and does not have unlimited
   * stock, sync the Craft stock levels with the Saasu response.
   * @param Variant $variant - The variant to sync with Saasu.
   */
  private function syncProductStockLevels($variant = false) {
    if ($variant && $variant->saasuId && !$variant->hasUnlimitedStock && CraftSaasuSync::saasuIntegrationValid()) {
      $url = $this->saasuApiUrl . 'Item/' . $variant->saasuId . $this->saasuAuth;
      $curl = curl_init($url);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      $curl_response = curl_exec($curl);
      if ($curl_response === false) {
        $info = curl_getinfo($curl);
        CraftSaasuSync::log('syncProductStockLevels() -> Error occured during curl exec.');
      }
      curl_close($curl);
      $decoded = json_decode($curl_response);
      if (is_object($decoded) && $decoded->StockOnHand) {
        $variant->stock = $decoded->StockOnHand;
      }
    }
  }
}
