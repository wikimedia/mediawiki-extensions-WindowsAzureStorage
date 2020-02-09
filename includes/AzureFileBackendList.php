<?php
/*
 * AzureFileBackend helper class to page through listsings.
 * Do not use this class from places outside AzureFileBackend.
 *
 * @ingroup FileBackend
 */
abstract class AzureFileBackendList implements Iterator {
	/** @var Array */
	protected $bufferIter = [];
	protected $bufferAfter = null; // string; list items *after* this path
	protected $pos = 0; // integer
	/** @var Array */
	protected $params = [];

	/** @var AzureFileBackend */
	protected $backend;
	protected $container; // string; container name
	protected $dir; // string; storage directory
	protected $suffixStart; // integer

	const PAGE_SIZE = 9000; // file listing buffer size

	/**
	 * @param $backend AzureFileBackend
	 * @param $fullCont string Resolved container name
	 * @param $dir string Resolved directory relative to container
	 * @param $params Array
	 */
	public function __construct( AzureFileBackend $backend, $fullCont, $dir, array $params ) {
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
	 * @param $container string Resolved container name
	 * @param $dir string Resolved path relative to container
	 * @param $after string|null
	 * @param $limit integer
	 * @param $params Array
	 * @return Traversable|array|null Returns null on failure
	 */
	abstract protected function pageFromList( $container, $dir, &$after, $limit, array $params );
}
