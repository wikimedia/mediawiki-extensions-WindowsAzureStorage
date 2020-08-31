<?php
/*
 * AzureFileBackend helper class to page through listsings.
 * Do not use this class from places outside AzureFileBackend.
 *
 * @ingroup FileBackend
 */
abstract class AzureFileBackendList implements Iterator {
	/** @var array */
	protected $bufferIter = [];
	/** @var string|null list items *after* this path */
	protected $bufferAfter = null;
	/** @var int */
	protected $pos = 0;
	/** @var array */
	protected $params = [];

	/** @var AzureFileBackend */
	protected $backend;
	/** @var string container name */
	protected $container;
	/** @var string storage directory */
	protected $dir;
	/** @var int */
	protected $suffixStart;

	/** file listing buffer size */
	const PAGE_SIZE = 9000;

	/**
	 * @param AzureFileBackend $backend
	 * @param string $fullCont Resolved container name
	 * @param string $dir Resolved directory relative to container
	 * @param array $params
	 */
	public function __construct( WindowsAzureFileBackend $backend, $fullCont, $dir, array $params ) {
		$this->backend = $backend;
		$this->container = $fullCont;
		$this->dir = $dir;
		if ( substr( $this->dir, -1 ) === '/' ) {
			$this->dir = substr( $this->dir, 0, -1 ); // remove trailing slash
		}
		if ( $this->dir == '' ) { // whole container
			$this->suffixStart = 0;
		} else { // dir within container
			$this->suffixStart = strlen( $this->dir ) + 1; // size of "path/to/dir/"
		}
		$this->params = $params;
	}

	/**
	 * @see Iterator::key()
	 * @return int
	 */
	public function key() {
		return $this->pos;
	}

	/**
	 * @see Iterator::next()
	 * @return void
	 */
	public function next() {
		// Advance to the next file in the page
		next( $this->bufferIter );
		++$this->pos;
		// Check if there are no files left in this page and
		// advance to the next page if this page was not empty.
		if ( !$this->valid() && count( $this->bufferIter ) ) {
			$this->bufferIter = $this->pageFromList(
				$this->container, $this->dir, $this->bufferAfter, self::PAGE_SIZE, $this->params
			); // updates $this->bufferAfter
		}
	}

	/**
	 * @see Iterator::rewind()
	 * @return void
	 */
	public function rewind() {
		$this->pos = 0;
		$this->bufferAfter = null;
		$this->bufferIter = $this->pageFromList(
			$this->container, $this->dir, $this->bufferAfter, self::PAGE_SIZE, $this->params
		); // updates $this->bufferAfter
	}

	/**
	 * @see Iterator::valid()
	 * @return bool
	 */
	public function valid() {
		if ( $this->bufferIter === null ) {
			return false; // some failure?
		} else {
			return ( current( $this->bufferIter ) !== false ); // no paths can have this value
		}
	}

	/**
	 * Get the given list portion (page)
	 *
	 * @param string $container Resolved container name
	 * @param string $dir Resolved path relative to container
	 * @param string|null &$after
	 * @param int $limit
	 * @param array $params
	 * @return Traversable|array|null Returns null on failure
	 */
	abstract protected function pageFromList( $container, $dir, &$after, $limit, array $params );
}
