<?php

namespace Message\Mothership\Voucher;

use Message\Mothership\Voucher\Event;

use Message\User\UserInterface;

use Message\Cog\DB;
use Message\Cog\Event\Dispatcher;
use Message\Cog\ValueObject\DateTimeImmutable;

/**
 * Face-value voucher creator.
 *
 * @author Joe Holdcroft <joe@message.co.uk>
 */
class Create implements DB\TransactionalInterface
{
	protected $_query;
	protected $_loader;
	protected $_currentUser;

	private $_dispatcher;

	protected $_idLength;
	protected $_expiryInterval;

	/**
	 * Constructor.
	 *
	 * @param DB\Query      $query       Database query instance
	 * @param Loader        $loader      Voucher loader
	 * @param UserInterface $currentUser The currently logged-in user
	 * @param Dispatcher    $dispatcher  The dispatcher to fire the event
	 */
	public function __construct(DB\Query $query, Loader $loader, UserInterface $currentUser, Dispatcher $dispatcher)
	{
		$this->_query       = $query;
		$this->_loader      = $loader;
		$this->_currentUser = $currentUser;
		$this->_dispatcher  = $dispatcher;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DB\Transaction $trans The transaction to use
	 */
	public function setTransaction(DB\Transaction $trans)
	{
		$this->_query = $trans;
	}

	/**
	 * Set the length to use for all voucher IDs.
	 *
	 * @param int $length
	 */
	public function setIdLength($length)
	{
		$this->_idLength = $length;
	}

	/**
	 * Set the interval to use to calculate the expiry date for all vouchers.
	 *
	 * This will only be used if the voucher passed to `create()` doesn't
	 * already have an expiry date set.
	 *
	 * Pass `null` to not calculate an expiry date.
	 *
	 * @param \DateInterval|null $interval The interval to use, or null to not
	 */
	public function setExpiryInterval(\DateInterval $interval = null)
	{
		$this->_expiryInterval = $interval;
	}

	/**
	 * Create a voucher.
	 *
	 * Before creation, the voucher's create metadata is set (unless it's
	 * already been set).
	 *
	 * If an expiry interval has been defined using `setExpiryInterval()`, and
	 * the voucher doesn't already have an expiry date set, the expiry date is
	 * calculated using the interval.
	 *
	 * The voucher is validated before creation.
	 *
	 * Once created, the voucher is re-loaded from the database and a fresh
	 * instance is returned, unless `setTransaction()` has been called, and the
	 * add query was made part of a transaction.
	 *
	 * @see validate
	 *
	 * @param  Voucher $voucher The voucher to create
	 *
	 * @return Voucher          The created voucher
	 */
	public function create(Voucher $voucher)
	{
		// Set created metadata if not already set
		if (!$voucher->authorship->createdAt()) {
			$voucher->authorship->create(
				new DateTimeImmutable,
				$this->_currentUser->id
			);
		}

		// Set the start date to the creation date if it's not already set
		if (!$voucher->startsAt) {
			$voucher->startsAt = $voucher->authorship->createdAt();
		}

		// Set the expiry date if it's not already set & there is an expiry interval defined
		if ($this->_expiryInterval && !$voucher->expiresAt) {
			$voucher->expiresAt = clone $voucher->startsAt;
			$voucher->expiresAt = $voucher->expiresAt->add($this->_expiryInterval);
		}

		// Force voucher ID to uppercase to avoid case sensitivity issues
		$voucher->id = strtoupper($voucher->id);

		// Validate the voucher before creation
		$this->_validate($voucher);

		// Create the voucher
		$this->_query->run('
			INSERT INTO
				voucher
			SET
				voucher_id           = :voucherID?s,
				created_at           = :createdAt?d,
				created_by           = :createdBy?in,
				starts_at            = :startsAt?dn,
				expires_at           = :expiresAt?dn,
				purchased_as_item_id = :purchasedAsItemID?in,
				currency_id          = :currencyID?s,
				amount               = :amount?f
		', array(
			'voucherID'         => $voucher->id,
			'createdAt'         => $voucher->authorship->createdAt(),
			'createdBy'         => $voucher->authorship->createdBy(),
			'startsAt'          => $voucher->startsAt,
			'expiresAt'         => $voucher->expiresAt,
			'purchasedAsItemID' => $voucher->purchasedAsItem ? $voucher->purchasedAsItem->id : null,
			'currencyID'        => $voucher->currencyID,
			'amount'            => $voucher->amount,
		));

		$this->_dispatcher->dispatch(
			Event\Events::VOUCHER_CREATE,
			new Event\VoucherEvent($voucher)
		);

		// If the query was replaced with a transaction, just return the voucher
		if ($this->_query instanceof DB\Transaction) {
			return $voucher;
		}

		// Otherwise, return a fresh re-loaded voucher
		return $this->_loader->getByID($voucher->id);
	}

	/**
	 * Validate a voucher to ensure it can be created.
	 *
	 * @param  Voucher $voucher The voucher to validate
	 *
	 * @throws \InvalidArgumentException If the voucher ID is not set
	 * @throws \InvalidArgumentException If the amount is not a positive value
	 * @throws \InvalidArgumentException If the length of the ID is different to
	 *                                   the configured ID length (if defined)
	 * @throws \InvalidArgumentException If the voucher currency ID is not set
	 * @throws \InvalidArgumentException If a voucher already exists with the
	 *                                   same ID
	 * @throws \InvalidArgumentException If the voucher has a "used at" date
	 */
	protected function _validate(Voucher $voucher)
	{

		if (!$voucher->id) {
			throw new \InvalidArgumentException('Cannot create voucher: ID is not set');
		}

		if (!$voucher->id || $voucher->amount <= 0) {
			throw new \InvalidArgumentException('Cannot create voucher: amount is not a positive value');
		}

		if ($this->_idLength && strlen($voucher->id) !== $this->_idLength) {
			throw new \InvalidArgumentException(sprintf(
				'Cannot create voucher: ID must be `%s` characters long, `%s` given',
				$this->_idLength,
				$voucher->id
			));
		}

		if (!$voucher->currencyID) {
			throw new \InvalidArgumentException('Cannot create voucher: currency ID is not set');
		}

		if ($this->_loader->getByID($voucher->id) instanceof Voucher) {
			throw new \InvalidArgumentException(sprintf(
				'Cannot create voucher: a voucher already exists with ID `%s`',
				$voucher->id
			));
		}

		if ($voucher->usedAt) {
			throw new \InvalidArgumentException('Cannot create voucher: it is already marked as used');
		}

		if ($voucher->expiresAt && $voucher->startsAt > $voucher->expiresAt) {
			throw new \InvalidArgumentException('Cannot create voucher: start date cannot be after expiry date');
		}
	}
}