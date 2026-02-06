<?php
/**
 * Windows Azure based file backend.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup FileBackend
 * @author Aaron Schulz
 * @author Markus Glaser
 * @author Robert Vogel
 * @author Thai Phan
 */

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\ContainerACL;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Blob\Models\PublicAccessType;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

/**
 * @brief Class for a Windows Azure based file backend
 *
 * This requires the WindowAzureSDK extension in order to work. Information on
 * how to install and set up this extension are all located at
 * https://www.mediawiki.org/wiki/Extension:WindowsAzureSDK.
 *
 * @ingroup FileBackend
 * @since 1.22
 */
class WindowsAzureFileBackend extends FileBackendStore {
	/** @var IBlob */
	private $proxy;

	/** @var string */
	private $connectionString;

	/**
	 * @see FileBackendStore::__construct()
	 * @param array $config
	 * Additional $config params include:
	 *   - azureAccount : Windows Azure storage account
	 *   - azureKey     : Windows Azure storage account key
	 */
	public function __construct( array $config ) {
		parent::__construct( $config );

		// Generate connection string to Windows Azure storage account
		$this->connectionString = 'DefaultEndpointsProtocol=https;'
					 . 'AccountName=' . $config['azureAccount'] . ';'
					 . 'AccountKey=' . $config['azureKey'];

		$this->proxy = BlobRestProxy::createBlobService( $this->connectionString );
	}

	/**
	 * @see FileBackendStore::resolveContainerName()
	 * @param string $container
	 * @return string|null
	 */
	protected function resolveContainerName( $container ) {
		$container = strtolower( $container );

		$container = preg_replace( '#[^a-z0-9\-]#', '', $container );
		$container = preg_replace( '#^-#', '', $container );
		$container = preg_replace( '#-$#', '', $container );
		$container = preg_replace( '#-{2,}#', '-', $container );

		return $container;
	}

	/**
	 * @see FileBackendStore::resolveContainerPath()
	 * @param string $container
	 * @param string $relStoragePath
	 * @return string|null
	 */
	protected function resolveContainerPath( $container, $relStoragePath ) {
		if ( !mb_check_encoding( $relStoragePath, 'UTF-8' ) ) {
			return null;
		} elseif ( strlen( urlencode( $relStoragePath ) ) > 1024 ) {
			return null;
		}
		return $relStoragePath;
	}

	/**
	 * @see FileBackendStore::isPathUsableInternal()
	 * @param string $storagePath
	 * @return bool
	 */
	public function isPathUsableInternal( $storagePath ) {
		[ $container, $rel ] = $this->resolveStoragePathReal( $storagePath );
		if ( $rel === null ) {
			return false; // invalid
		}

		try {
			$this->proxy->getContainerProperties( $container );
			return true; // container exists
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					break;

				default: // some other exception?
					$this->handleException( $e, null, __METHOD__, [ 'path' => $storagePath ] );
			}

			return false;
		}
	}

	/**
	 * @see FileBackendStore::doCreateInternal()
	 * @param array $params
	 * @return Status
	 */
	protected function doCreateInternal( array $params ) {
		$status = Status::newGood();

		[ $dstCont, $dstRel ] = $this->resolveStoragePathReal( $params['dst'] );
		if ( $dstRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
			return $status;
		}

		// (a) Get a SHA-1 hash of the object
		$sha1Hash = Wikimedia\base_convert( sha1( $params['content'] ), 16, 36, 31 );

		// (b) Actually create the object
		try {
			$options = new CreateBlockBlobOptions();
			$options->setMetadata( [ 'sha1base36' => $sha1Hash ] );
			$options->setContentType( mime_content_type( $params['src'] ) );
			$this->proxy->createBlockBlob( $dstCont, $dstRel, (string)$params['content'], $options );
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					$status->fatal( 'backend-fail-create', $params['dst'] );
					break;

				default: // some other exception?
					$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doStoreInternal()
	 * @param array $params
	 * @return Status
	 */
	protected function doStoreInternal( array $params ) {
		$status = Status::newGood();

		[ $dstCont, $dstRel ] = $this->resolveStoragePathReal( $params['dst'] );
		if ( $dstRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
			return $status;
		}

		// (a) Get a SHA-1 hash of the object
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$sha1Hash = @sha1_file( $params['src'] );
		if ( $sha1Hash === false ) { // source doesn't exist?
			$status->fatal( 'backend-fail-store', $params['src'], $params['dst'] );
			return $status;
		}
		$sha1Hash = Wikimedia\base_convert( $sha1Hash, 16, 36, 31 );

		// (b) Actually store the object
		try {
			$options = new CreateBlockBlobOptions();
			$options->setMetadata( [ 'sha1base36' => $sha1Hash ] );
			$options->setContentType( mime_content_type( $params['src'] ) );
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$fp = @fopen( $params['src'], 'rb' );
			if ( !$fp ) {
				$status->fatal( 'backend-fail-store', $params['src'], $params['dst'] );
			} else {
				$this->proxy->createBlockBlob( $dstCont, $dstRel, $fp, $options );
				fclose( $fp );
			}
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					$status->fatal( 'backend-fail-store', $params['src'], $params['dst'] );
					break;

				default: // some other exception?
					$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doCopyInternal()
	 * @param array $params
	 * @return Status
	 */
	protected function doCopyInternal( array $params ) {
		$status = Status::newGood();

		[ $srcCont, $srcRel ] = $this->resolveStoragePathReal( $params['src'] );
		if ( $srcRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		[ $dstCont, $dstRel ] = $this->resolveStoragePathReal( $params['dst'] );
		if ( $dstRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
			return $status;
		}

		try {
			$this->proxy->copyBlob( $dstCont, $dstRel, $srcCont, $srcRel );
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					if ( empty( $params['ignoreMissingSource'] ) ) {
						$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );
					}
					break;

				default: // some other exception?
					$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doDeleteInternal()
	 * @param array $params
	 * @return Status
	 */
	protected function doDeleteInternal( array $params ) {
		$status = Status::newGood();

		[ $srcCont, $srcRel ] = $this->resolveStoragePathReal( $params['src'] );
		if ( $srcRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		try {
			$this->proxy->deleteBlob( $srcCont, $srcRel );
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					if ( empty( $params['ignoreMissingSource'] ) ) {
						$status->fatal( 'backend-fail-delete', $params['src'] );
					}
					break;

				default: // some other exception?
					$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doPrepareInternal()
	 * @param string $fullCont
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 */
	protected function doPrepareInternal( $fullCont, $dir, array $params ) {
		$status = Status::newGood();

		try {
			$this->proxy->createContainer( $fullCont );
			if ( !empty( $params['noAccess'] ) ) {
				// Make container private to end-users...
				$status->merge( $this->doSecureInternal( $fullCont, $dir, $params ) );
			} else {
				// Make container public to end-users...
				$status->merge( $this->doPublishInternal( $fullCont, $dir, $params ) );
			}
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
				case 409: // container already exists
					break;

				default: // some other exception?
					$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doSecureInternal()
	 * @param string $fullCont
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 */
	protected function doSecureInternal( $fullCont, $dir, array $params ) {
		$status = Status::newGood();
		if ( empty( $params['noAccess'] ) ) {
			return $status; // nothing to do
		}

		// Restrict container from end-users...
		try {
			$acl = new ContainerAcl();
			$acl->setPublicAccess( PublicAccessType::NONE );
			$this->proxy->setContainerAcl( $fullCont, $acl );
		} catch ( ServiceException $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doPublishInternal()
	 * @param string $fullCont
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 */
	protected function doPublishInternal( $fullCont, $dir, array $params ) {
		$status = Status::newGood();

		// Unrestrict container from end-users...
		try {
			$acl = new ContainerAcl();
			$acl->setPublicAccess( PublicAccessType::BLOBS_ONLY );
			$this->proxy->setContainerAcl( $fullCont, $acl );
		} catch ( ServiceException $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doFileExists()
	 * @param array $params
	 * @return array|bool|null
	 */
	protected function doGetFileStat( array $params ) {
		[ $srcCont, $srcRel ] = $this->resolveStoragePathReal( $params['src'] );
		if ( $srcRel === null ) {
			return false; // invalid storage path
		}

		try {
			// @TODO: pass $metadata to addMissingMetadata() to avoid round-trips
			$this->addMissingMetadata( $srcCont, $srcRel, $params['src'] );
			$properties = $this->proxy->getBlobProperties( $srcCont, $srcRel );
			$timestamp = $properties->getProperties()->getLastModified()->getTimestamp();
			$size = $properties->getProperties()->getContentLength();
			$metadata = $properties->getMetadata();
			$sha1 = $metadata['sha1base36'];
			$stat = [
				'mtime' => wfTimestamp( TS_MW, $timestamp ),
				'size'  => $size,
				'sha1'  => $sha1
			];
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					$stat = false;
					break;

				default: // some other exception?
					$stat = null;
					$this->handleException( $e, null, __METHOD__, $params );
			}
		}

		return $stat;
	}

	/**
	 * Fill in any missing blob metadata and save it to Azure
	 *
	 * @param string $srcCont Container name
	 * @param string $srcRel Blob name
	 * @param string $path Storage path to object
	 * @return bool Success
	 * @throws Exception Azure Storage service exception
	 */
	protected function addMissingMetadata( $srcCont, $srcRel, $path ) {
		$metadata = $this->proxy->getBlobMetadata( $srcCont, $srcRel )->getMetadata();
		if ( isset( $metadata['sha1base36'] ) ) {
			return true; // nothing to do
		}
		trigger_error( "$path was not stored with SHA-1 metadata.", E_USER_WARNING );
		$status = Status::newGood();
		$scopeLockS = $this->getScopedFileLocks( [ $path ], LockManager::LOCK_UW, $status );
		if ( $status->isOK() ) {
			$tmpFile = $this->getLocalCopy( [ 'src' => $path, 'latest' => 1 ] );
			if ( $tmpFile ) {
				$hash = $tmpFile->getSha1Base36();
				if ( $hash !== false ) {
					$this->proxy->setBlobMetadata( $srcCont, $srcRel, [ 'sha1base36' => $hash ] );
					return true; // success
				}
			}
		}
		trigger_error( "Unable to set SHA-1 metadata for $path", E_USER_WARNING );
		// Set the SHA-1 metadata to 0 (setting to false doesn't seem to work)
		// @TODO: don't permanently set the object metadata here, just make sure this PHP
		//        request doesn't keep trying to download the file again and again.
		$this->proxy->setBlobMetadata( $srcCont, $srcRel, [ 'sha1base36' => 0 ] );
		return false; // failed
	}

	/**
	 * @see FileBackendStore::doDirectoryExists()
	 * @param string $fullCont
	 * @param string $dir
	 * @param array $params
	 * @return bool|null
	 */
	protected function doDirectoryExists( $fullCont, $dir, array $params ) {
		try {
			$prefix = ( $dir == '' ) ? null : "{$dir}/";

			$options = new ListBlobsOptions();
			$options->setMaxResults( 1 );
			$options->setPrefix( $prefix );

			$blobs = $this->proxy->listBlobs( $fullCont, $options )->getBlobs();

			return ( count( $blobs ) > 0 );
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					return false;

				default: // some other exception?
					$this->handleException( $e, null, __METHOD__,
						[ 'cont' => $fullCont, 'dir' => $dir ] );
					return null;
			}
		}
	}

	/**
	 * @see FileBackendStore::getDirectoryListInternal()
	 * @param string $fullCont
	 * @param string $dir
	 * @param array $params
	 * @return AzureFileBackendDirList
	 */
	public function getDirectoryListInternal( $fullCont, $dir, array $params ) {
		return new AzureFileBackendDirList( $this, $fullCont, $dir, $params );
	}

	/**
	 * @see FileBackendStore::getFileListInternal()
	 * @param string $fullCont
	 * @param string $dir
	 * @param array $params
	 * @return AzureFileBackendFileList
	 */
	public function getFileListInternal( $fullCont, $dir, array $params ) {
		return new AzureFileBackendFileList( $this, $fullCont, $dir, $params );
	}

	/**
	 * @param string $fullCont
	 * @param string $dir
	 * @param string|null &$after
	 * @param int $limit
	 * @param array $params
	 * @return array List of relative paths of files under $dir
	 */
	public function getDirListPageInternal( $fullCont, $dir, &$after, $limit, array $params ) {
		$dirs = [];
		if ( $after === INF ) {
			return $dirs;
		}

		try {
			$prefix = ( $dir == '' ) ? null : "{$dir}/";

			$options = new ListBlobsOptions();
			$options->setMaxResults( $limit );
			$options->setMarker( $after );
			$options->setPrefix( $prefix );

			$objects = [];

			if ( !empty( $params['topOnly'] ) ) {
				// Blobs are listed in alphabetical order in the response body, with
				// upper-case letters listed first.
				// @TODO: use prefix+delimeter here
				$blobs = $this->proxy->listBlobs( $fullCont, $options )->getBlobs();
				foreach ( $blobs as $blob ) {
					$name = $blob->getName();
					if ( $prefix === null ) {
						if ( !preg_match( '#\/#', $name ) ) {
							continue;
						}
						$dirray = preg_split( '#\/#', $name );
						$name = $dirray[0] . '/';
						$objects[] = $name;
					}
					$name = preg_replace( '#[^/]*$#', '', $name );
					if ( preg_match( '#^' . $prefix . '(\/|)$#', $name ) ) {
						continue;
					}
					$dirray = preg_split( '#\/#', $name );
					$elements = count( preg_split( '#\/#', $prefix ) );
					$name = '';
					for ( $i = 0; $i < $elements; $i++ ) {
						$name = $name . $dirray[$i] . '/';
					}
					$objects[] = $name;
				}
				$dirs = array_unique( $objects );
			} else {
				// Get directory from last item of prior page
				$lastDir = $this->getParentDir( $after ); // must be first page
				$blobs = $this->proxy->listBlobs( $fullCont, $options )->getBlobs();

				// Generate an array of blob names
				foreach ( $blobs as $blob ) {
					array_push( $objects, $blob->getName() );
				}

				foreach ( $objects as $object ) { // files
					$objectDir = $this->getParentDir( $object ); // directory of object
					if ( $objectDir !== false && $objectDir !== $dir ) {
						if ( strcmp( $objectDir, $lastDir ) > 0 ) {
							$pDir = $objectDir;
							do { // add dir and all its parent dirs
								$dirs[] = "{$pDir}/";
								$pDir = $this->getParentDir( $pDir );
							} while ( $pDir !== false // sanity
								&& strcmp( $pDir, $lastDir ) > 0 // not done already
								&& strlen( $pDir ) > strlen( $dir ) // within $dir
							);
						}
						$lastDir = $objectDir;
					}
				}
			}

			if ( count( $objects ) < $limit ) {
				$after = INF; // avoid a second RTT
			} else {
				$after = end( $objects ); // update last item
			}
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					break;

				default: // some other exception?
					$this->handleException( $e, null, __METHOD__,
						[ 'cont' => $fullCont, 'dir' => $dir ] );
			}
		}

		return $dirs;
	}

	/**
	 * @param string $path
	 * @return string|false
	 */
	protected function getParentDir( $path ) {
		return ( strpos( $path, '/' ) !== false ) ? dirname( $path ) : false;
	}

	/**
	 * Do not call this function outside of AzureFileBackendFileList
	 *
	 * @param string $fullCont
	 * @param string $dir
	 * @param string|null &$after
	 * @param int $limit
	 * @param array $params
	 * @return array List of relative paths of files under $dir
	 */
	public function getFileListPageInternal( $fullCont, $dir, &$after, $limit, array $params ) {
		$files = [];
		if ( $after === INF ) {
			return $files;
		}

		try {
			$prefix = ( $dir == '' ) ? null : "{$dir}/";

			$options = new ListBlobsOptions();
			$options->setMaxResults( $limit );
			$options->setMarker( $after );
			$options->setPrefix( $prefix );

			$objects = [];

			if ( !empty( $params['topOnly'] ) ) {
				$options->setDelimiter( '/' );

				$blobs = $this->proxy->listBlobs( $fullCont, $options )->getBlobs();

				foreach ( $blobs as $blob ) {
					array_push( $objects, $blob->getName() );
				}

				foreach ( $objects as $object ) {
					if ( substr( $object, -1 ) !== '/' ) {
						$files[] = $object;
					}
				}
			} else {
				$blobs = $this->proxy->listBlobs( $fullCont, $options )->getBlobs();

				foreach ( $blobs as $blob ) {
					array_push( $objects, $blob->getName() );
				}

				$files = $objects;
			}

			if ( count( $objects ) < $limit ) {
				$after = INF;
			} else {
				$after = end( $objects );
			}
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					break;

				default: // some other exception?
					$this->handleException( $e, null, __METHOD__,
						[ 'cont' => $fullCont, 'dir' => $dir ] );
			}
		}

		return $files;
	}

	/**
	 * @see FileBackendStore::doGetFileSha1base36()
	 * @param array $params
	 * @return bool
	 */
	protected function doGetFileSha1base36( array $params ) {
		$stat = $this->getFileStat( $params );
		if ( $stat ) {
			return $stat['sha1'];
		} else {
			return false;
		}
	}

	/**
	 * @see FileBackendStore::doStreamFile()
	 * @param array $params
	 * @return Status
	 */
	protected function doStreamFile( array $params ) {
		$status = Status::newGood();

		[ $srcCont, $srcRel ] = $this->resolveStoragePathReal( $params['src'] );
		if ( $srcRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
		}

		try {
			$contents = $this->proxy->getBlob( $srcCont, $srcRel );
			$contentStream = $contents->getContentStream();
			file_put_contents(
				'php://output',
				header( 'Content-Type:' . $contents->getProperties()->getContentType() )
			);
			file_put_contents( 'php://output', $contentStream );
		} catch ( ServiceException $e ) {
			switch ( $e->getCode() ) {
				case 404:
					$status->fatal( 'backend-fail-stream', $params['src'] );
					break;

				default: // some other exception?
					$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		return $status;
	}

	/**
	 * @see FileBackendStore::doGetLocalCopyMulti()
	 * @param array $params
	 * @return null|TempFSFile
	 */
	protected function doGetLocalCopyMulti( array $params ) {
		$tmpFiles = [];

		$ep = array_diff_key( $params, [ 'srcs' => 1 ] ); // for error logging
		// Blindly create tmp files and stream to them, catching any exception if the file does
		// not exist. Doing a stat here is useless causes infinite loops in addMissingMetadata().
		foreach ( array_chunk( $params['srcs'], $params['concurrency'] ) as $pathBatch ) {
			foreach ( $pathBatch as $path ) { // each path in this concurrent batch
				[ $srcCont, $srcRel ] = $this->resolveStoragePathReal( $path );
				if ( $srcRel === null ) {
					$tmpFiles[$path] = null;
					continue;
				}
				$tmpFile = null;
				try {
					// Get source file extension
					$ext = FileBackend::extensionFromPath( $path );
					// Create a new temporary file...
					$tmpFile = TempFSFile::factory( 'localcopy_', $ext );
					if ( $tmpFile ) {
						$tmpPath = $tmpFile->getPath();
						$contents = $this->proxy->getBlob( $srcCont, $srcRel )->getContentStream();
						file_put_contents( $tmpPath, $contents );
					}
				} catch ( ServiceException $e ) {
					$tmpFile = null;
					switch ( $e->getCode() ) {
						case 404:
							break;

						default: // some other exception?
							$this->handleException( $e, null, __METHOD__, [ 'src' => $path ] + $ep );
					}
				}
				$tmpFiles[$path] = $tmpFile;
			}
		}

		return $tmpFiles;
	}

	/**
	 * @see FileBackendStore::directoriesAreVirtual()
	 * @return bool
	 */
	protected function directoriesAreVirtual() {
		return true;
	}

	/**
	 * Log an unexpected exception for this backend.
	 * This also sets the Status object to have a fatal error.
	 *
	 * @param Exception $e
	 * @param Status|null $status
	 * @param string $func
	 * @param array $params
	 * @return void
	 */
	protected function handleException( Exception $e, $status, $func, array $params ) {
		if ( $status instanceof Status ) {
			$status->fatal( 'backend-fail-internal', $this->name );
		}
		if ( $e->getMessage() ) {
			trigger_error( "$func:" . $e->getMessage(), E_USER_WARNING );
		}
		wfDebugLog( 'AzureBackend',
			get_class( $e ) . " in '{$func}' (given '" . FormatJson::encode( $params ) . "')" .
			( $e->getMessage() ? ": {$e->getMessage()}" : "" )
		);
	}
}
