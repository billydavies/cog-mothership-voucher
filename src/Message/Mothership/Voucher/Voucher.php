<?php

namespace Message\Mothership\Voucher;

/**
 * Represents a single face-value voucher.
 *
 * @author Joe Holdcroft <joe@message.co.uk>
 */
class Voucher
{
	public $authorship;

	public $code;
	public $currencyID;
	public $amount;

	public $expiresAt;
	public $usedAt;
	public $purchasedAsItem;
	public $usage = array();

	/**
	 * Get the total amount of this voucher that has been used.
	 *
	 * @return float
	 */
	public function getAmountUsed()
	{
		$return = 0;

		foreach ($this->usage as $payment) {
			$return += $payment->amount;
		}

		return $return;
	}

	/**
	 * Get the remaining balance for this voucher (the original amount less the
	 * amount used to date).
	 *
	 * @return float
	 */
	public function getBalance()
	{
		return $this->amount - $this->getAmountUsed();
	}
}