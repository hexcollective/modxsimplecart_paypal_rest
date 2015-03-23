<?php
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\RelatedResources;
use PayPal\Api\ShippingAddress;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

require_once dirname(__FILE__) . '/lib/autoload.php';

class SimpleCartPayPalRestPaymentGateway extends SimpleCartGateway
{
	public $hasInstall = 'paypalrest';
	/**
	 * @var ApiContext
	 */
	private $apiContext;

	public function submit()
	{
		$this->modx->lexicon->load('simplecart:cart', 'simplecart:methods');
		$currency = $this->getCurrency();
		$orderTotals = $this->order->get('totals');
		try {
			$payer = new Payer();
			$payer->setPaymentMethod('paypal');
			$items = new ItemList();

			if (intval($this->getProperty('shipping'))) {
				$address = $this->modx->getObject('simpleCartOrderAddress', array(
					'order_id' => $this->order->get('id'),
					'type' => 'delivery',
				));
				if ($address) {
					$shippingAddress = new ShippingAddress();
					try {
						$name = trim($address->get('firstname') . ' ' . $address->get('lastname'));
						if ($name === '') {
							throw new Exception('Name of the recipient is required');
						} else {
							if (strlen($name) > 50) {
								throw new Exception('Name of the recipient should not contain more than 50 characters');
							}
						}
						$shippingAddress->setRecipientName($name);
						for ($i = 1; $i <= 2; $i++) {
							$line = trim($address->get('address' . $i));
							if ($line === '') {
								if ($i == 1) {
									throw new Exception('Line 1 of the address is required');
								} else {
									continue;
								}
							} else {
								if (strlen($line) > 100) {
									throw new Exception('Line ' . $i . ' of the address should not contain more than 100 characters');
								}
							}
							call_user_func(array(
								$shippingAddress,
								'setLine' . $i,
							), $line);
						}
						$city = trim($address->get('city'));
						if ($city === '') {
							throw new Exception('City is required');
						} else {
							if (strlen($city) > 50) {
								throw new Exception('City should not contain more than 50 characters');
							}
						}
						$shippingAddress->setCity($city);
						$country = trim($address->get('country'));
						if ($country === '') {
							throw new Exception('Country code is required');
						} else {
							if (strlen($country) != 2) {
								throw new Exception('Country should be presented as a 2-letter country code');
							}
						}
						$shippingAddress->setCountryCode(strtoupper($country));
						$zip = trim($address->get('zip'));
						if ($zip !== '') {
							$shippingAddress->setPostalCode($zip);
						}
						$state = trim($address->get('state'));
						if ($state !== '') {
							$shippingAddress->setState($state);
						}
						$items->setShippingAddress($shippingAddress);
					} catch (Exception $e) {
						$this->order->addLog('Shipping Address Error', $e->getMessage());
					}
				}
			}

			$discount = isset($orderTotals['discount_percent']) && $orderTotals['discount_percent'] > 0 ? $orderTotals['discount_percent'] : 0;
			$discount = 1 - $discount / 100;

			$productsTotal = 0;
			$productsTax = 0;

			/**
			 * @var simpleCartOrderProduct[] $products
			 */
			$products = $this->order->getMany('Product');
			foreach ($products as $product) {
				$price = $this->round($product->get('total'));
				$quantity = $product->get('quantity');
				$item = new Item();
				$item
					->setName($product->get('title'))
					->setSku($product->get('productcode'))
					->setQuantity($quantity)
					->setPrice($price)
					->setCurrency($currency);
				$totals = $product->get('totals');
				if (isset($totals['price_ex_vat']) && $totals['price_ex_vat'] > 0) {
					$priceExVat = $this->round($totals['price_ex_vat']);
					if ($priceExVat != $price) {
						$tax = $this->round(($price - $priceExVat) * $discount);
						$productsTax += $tax * $quantity;
						$item
							->setPrice($priceExVat)
							->setTax($tax);
						$price = $priceExVat;
					}
				}
				$items->addItem($item);
				$productsTotal += $price * $quantity;
			}

			$details = array(
				'delivery' => 0,
				'fee' => 0,
				'tax' => 0,
			);

			$amount = new Amount();
			$amount->setCurrency($currency);

			$totalAmount = $this->round($this->order->get('total'));
			$amount->setTotal($totalAmount);

			$taxDiff = 0;

			if (!empty($orderTotals['vat_total']) && isset($orderTotals['price_ex_vat'])) {
				if ($orderTotals['vat_total'] > 0) {
					$totalTax = $this->round($orderTotals['vat_total']);
					if ($productsTax != $totalTax) {
						$taxDiff = $this->round($totalTax - $productsTax);
						$totalTax = $productsTax;
					}
					$details['tax'] = $totalTax;
				}
			}

			$details['discount'] = isset($orderTotals['discount']) && $orderTotals['discount'] > 0 ? $this->round($orderTotals['discount']) : 0;
			if ($taxDiff < 0) {
				$details['discount'] -= $taxDiff;
			}

			if (isset($orderTotals['delivery']) && $orderTotals['delivery'] > 0) {
				$details['delivery'] = $this->round($orderTotals['delivery']);
			}

			$fee = isset($orderTotals['payment']) && $orderTotals['payment'] > 0 ? $this->round($orderTotals['payment']) : 0;
			if ($taxDiff > 0) {
				$fee += $taxDiff;
			}
			if ($fee) {
				$details['fee'] = $fee;
			}

			$details['discount'] = -1 * $details['discount'];
			$subTotalAmount = $totalAmount - array_sum($details);
			$subTotalAmountDiff = $this->round($subTotalAmount - $productsTotal);

			if ($subTotalAmountDiff) {
				$details[$subTotalAmountDiff > 0 ? 'fee' : 'discount'] += $subTotalAmountDiff;
			}

			$subTotalAmount = $totalAmount - array_sum(array_diff_key($details, array_flip(array(
				'discount',
			))));

			if ($totalAmount != $subTotalAmount) {
				$amountDetails = new Details();
				if ($details['tax']) {
					$amountDetails->setTax($details['tax']);
				}
				if ($details['discount']) {
					$item = new Item();
					$item
						->setName($this->modx->lexicon('simplecart.cart.discount'))
						->setQuantity(1)
						->setPrice($details['discount'])
						->setCurrency($currency);
					$items->addItem($item);
				}
				if ($details['delivery']) {
					$amountDetails->setShipping($details['delivery']);
				}
				if ($details['fee']) {
					$amountDetails->setHandlingFee($details['fee']);
				}
				$amountDetails->setSubtotal($subTotalAmount);
				$amount->setDetails($amountDetails);
			}

			$transaction = new Transaction();
			$transaction
				->setAmount($amount)
				->setItemList($items);
			$transaction->setInvoiceNumber($this->order->get('ordernr'));
			$urls = new RedirectUrls();

			$redirectUrl = $this->getRedirectUrl();
			$redirectUrl .= (strpos($redirectUrl, '?') === false ? '?' : '&') . http_build_query(array(
				'order_id' => $this->order->get('id'),
				'utm_nooverride' => 1,
			));

			$urls->setCancelUrl($redirectUrl);
			$urls->setReturnUrl($redirectUrl);
			$payment = new Payment();
			$payment
				->setIntent('sale')
				->setPayer($payer)
				->setRedirectUrls($urls)
				->setTransactions(array(
					$transaction,
				));

			if ($this->isDebug()) {
				$this->order->addLog('Payment Created', $payment->toJSON());
			}

			$payment->create($this->getApiContext());

			$paymentId = trim($payment->getId());
			$this->order->addLog('PayPal Payment ID', $paymentId);
			$this->order->save();

			foreach ($payment->getLinks() as $link) {
				if ($link->getRel() == 'approval_url') {
					$_SESSION[$this->getPaymentIdKey()] = $paymentId;
					$this->modx->sendRedirect($link->getHref());
					return true;
				}
			}

			throw new Exception('No Approval URL was found');
		} catch (Exception $e) {
			$this->order->addLog('PayPal Failure', $e->getMessage());
			$this->order->set('status', 'payment_failed');
			$this->order->save();
			$this->setRedirectUrl($this->getRedirectUrl(), array(
				'error' => 'true',
			));
		}
		return false;
	}

	public function verify()
	{
		if ($this->hasProperty('order_id')) {
			$this->setOrder($this->getProperty('order_id'));
			if ($this->hasOrder() && $this->order->get('status') == 'new') {
				$payment = $this->order->getOne('Payment');
				if ($payment && $payment->get('name') == 'paypalrest') {
					$paymentIdKey = $this->getPaymentIdKey();
					if (isset($_SESSION[$paymentIdKey])) {
						$payerId = trim($this->getProperty('PayerID'));
						if ($payerId !== '') {
							$apiContext = $this->getApiContext();
							$payment = null;
							try {
								$payment = Payment::get($_SESSION[$paymentIdKey], $apiContext);
							} catch (Exception $e) {
								$this->order->addLog('Unable to get the payment', $e->getMessage());
								$this->order->setStatus('payment_failed');
							}
							if ($payment) {
								$transactions = $payment->getTransactions();
								if (count($transactions)) {
									/**
									 * @var Transaction $transaction
									 */
									$transaction = array_shift($transactions);
									$currency = $this->getCurrency();
									$amount = $transaction->getAmount();
									$total = $this->round($this->order->get('total'));
									if ($payment->getState() == 'created' && $amount->getCurrency() == $currency && $amount->getTotal() >= $total) {
										$execution = new PaymentExecution();
										$execution->setPayerId($payerId);
										try {
											$result = $payment->execute($execution, $apiContext);
											if ($this->isDebug()) {
												$this->order->addLog('Payment Executed', $result->toJSON());
											}
											if ($result->getState() == 'approved') {
												$transactions = $result->getTransactions();
												if (!empty($transactions)) {
													$transaction = array_shift($transactions);
													$resources = $transaction->getRelatedResources();
													if (!empty($resources)) {
														/**
														 * @var RelatedResources $resource
														 */
														$resource = array_shift($resources);
														$sale = $resource->getSale();
														$amount = $sale->getAmount();
														if ($sale->getState() == 'completed' && $amount->getCurrency() == $currency && $amount->getTotal() >= $total) {
															$this->order->addLog('PayPal Transaction ID', $sale->getId());
															$this->order->setStatus('finished');
														}
													}
												}
											}
										} catch (Exception $e) {
											$this->order->addLog('Unable to execute the payment', $e->getMessage());
											$this->order->setStatus('payment_failed');
										}
									}
								} else {
									$this->order->addLog('Payment has no transactions', $payment->toJSON());
									$this->order->setStatus('payment_failed');
								}
							}
						}
						unset($_SESSION[$paymentIdKey]);
					}
				}
			}
		}
		return parent::verify();
	}

	protected function getApiContext()
	{
		if ($this->apiContext === null) {
			$this->apiContext = new ApiContext(new OAuthTokenCredential($this->getProperty('clientId'), $this->getProperty('clientSecret')));
			$this->apiContext->setConfig(array(
				'mode' => intval($this->getProperty('sandbox', 0, 'isset')) ? 'sandbox' : 'live',
				'http.ConnectionTimeOut' => 30,
				'log.LogEnabled' => true,
				'log.FileName' => $this->modx->getCachePath() . 'logs/paypal.log',
				'log.LogLevel' => $this->isDebug() ? 'FINE' : 'WARN',
				'validation.level' => 'log',
			));
		}
		return $this->apiContext;
	}

	protected function getCurrency()
	{
		$currency = trim($this->simplecart->currency->get('name'));
		if ($currency === '') {
			$currency = $this->getProperty('currency', 'EUR');
		}
		if ($this->getApiContext()->get('mode') == 'sandbox') {
			$currency = $this->getProperty('sandbox.currency', 'USD', 'isset');
		}
		return $currency;
	}

	protected function getPaymentIdKey()
	{
		return get_class($this) . '_' . $this->order->get('id') . '_payment_id';
	}

	protected function isDebug()
	{
		return intval($this->getProperty('debug'));
	}

	protected function round($value)
	{
		return round($value, 2);
	}
}
