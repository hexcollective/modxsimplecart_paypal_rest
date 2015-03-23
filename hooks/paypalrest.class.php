<?php
class SimpleCartPayPalRestPaymentGatewayInstallProcessor extends modObjectGetProcessor
{
	public $classKey = 'simpleCartMethod';

	public function process()
	{
		if ($this->object->get('name') != 'paypalrest') {
			return $this->failure('unsupported_method');
		}
		$properties = array(
			'clientId' => '',
			'clientSecret' => '',
			'shipping' => 1,
			'sandbox' => 0,
			'sandbox.currency' => 'USD',
			'debug' => 0,
		);
		$methodId = $this->object->get('id');
		foreach ($properties as $name => $value) {
			$this->modx->log(modX::LOG_LEVEL_INFO, 'Creating setting "' . $name . '"');
			$conditions = array(
				'method' => $methodId,
				'name' => $name,
			);
			$property = $this->modx->getObject('simpleCartMethodProperty', $conditions);
			if ($property) {
				$this->modx->log(modX::LOG_LEVEL_INFO, ' -> Already exists');
				continue;
			}
			$property = $this->modx->newObject('simpleCartMethodProperty', array_merge($conditions, array(
				'value' => $value,
			)));
			if ($property->save()) {
				$this->modx->log(modX::LOG_LEVEL_INFO, ' -> Success');
			} else {
				$this->modx->log(modX::LOG_LEVEL_ERROR, ' -> Failed');
			}
		}
		return parent::process();
	}
}

return 'SimpleCartPayPalRestPaymentGatewayInstallProcessor';
