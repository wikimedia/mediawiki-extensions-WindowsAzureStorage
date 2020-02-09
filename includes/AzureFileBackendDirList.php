<?php
/**
 * Iterator for listing directories
 */
class AzureFileBackendDirList extends AzureFileBackendList {
	/**
	 * @see Iterator::current()
	 * @return string|bool String (relative path) or false
	 */
	public function current() {
		return substr( current( $this->bufferIter ), $this->suffixStart, -1 );
	}

	/**
	 * @see AzureFileBackendList::pageFromList()
	 * @return Array|null
	 */
	public function pageFromList( $container, $dir, &$after, $limit, array $params ) {
		return $this->backend->getDirListPageInternal( $container, $dir, $after, $limit, $params );
	}
}
