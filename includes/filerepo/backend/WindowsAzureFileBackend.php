<?php
/**
 * @file
 * @ingroup FileBackend
 * @author Markus Glaser
 * @author Robert Vogel
 * @author Hallo Welt! - Medienwerkstatt GmbH
 */

/**
 * Copied and modified from Swift FileBackend:
 * 
 * Class for a Windows Azure Blob Storage based file backend.
 * Status messages should avoid mentioning the Azure account name
 * Likewise, error suppression should be used to avoid path disclosure.
 *
 * This requires the PHPAzure library to be present,
 * which is available at http://phpazure.codeplex.com/.
 * All of the library classes must be registed in $wgAutoloadClasses.
 * You may use the WindowsAzureSDK MediaWiki extension to fulfill this
 * requirement.
 *
 * @ingroup FileBackend
 */
class WindowsAzureFileBackend extends FileBackend {
	
	/** @var Microsoft_WindowsAzure_Storage_Blob */
	protected $storageClient = null;

	/** @var Array Map of container names to Azure container names */
	protected $containerPaths = array();
	
	/**
	 * @see FileBackend::__construct()
	 * Additional $config params include:
	 *    azureHost      : Windows Azure server URL
	 *    azureAccount   : Windows Azure user used by MediaWiki
	 *    azureKey       : Authentication key for the above user (used to get sessions)
	 *    //azureContainer : Identifier of the container. (Optional. If not provided wikiId will be used as container name)
     *    containerPaths : Map of container names to Azure container names
	 */
	public function __construct( array $config ) {
		parent::__construct( $config );
		$this->storageClient = new Microsoft_WindowsAzure_Storage_Blob(
				$config['azureHost'],
				$config['azureAccount'],
				$config['azureKey']
		);

		$this->containerPaths = (array)$config['containerPaths'];
	}

	/**
	 * @see FileBackend::resolveContainerPath()
	 */
	protected function resolveContainerPath( $container, $relStoragePath ) {
		//Azure container naming conventions; http://msdn.microsoft.com/en-us/library/dd135715.aspx

		if ( strlen( urlencode( $relStoragePath ) ) > 1024 ) {
			return null;
		}
		
		// Get absolute path given the container base dir
		if ( isset( $this->containerPaths[$container] ) ) {
			return $this->containerPaths[$container] . "/{$relStoragePath}";
		}
		// TODO: Should storagepath not be urlencoded?
		// TODO: return null
		return $relStoragePath;
	}

	/**
	 * @see FileBackend::doStoreInternal()
	 */
	function doStoreInternal( array $params ) {
		$status = Status::newGood();
		list( $dstCont, $dstRel ) = $this->resolveStoragePath( $params['dst'] );
		try {
			$result = $this->storageClient->putBlob( $dstCont, $dstRel, $params['src']);
		}
		catch ( Exception $e ) {
			// TODO: Read exception. Are there different ones?
			$status->fatal( 'backend-fail-store', $dstRel, $dstCont );
		}
		return $status;
	}

	/**
	 * @see FileBackend::doCopyInternal()
	 */
	function doCopyInternal( array $params ) {
		$status = Status::newGood();
		list( $srcContainer, $srcDir ) = $this->resolveStoragePath( $params['src'] );
		list( $dstContainer, $dstDir ) = $this->resolveStoragePath( $params['dst'] );

		// TODO: check for null
		try {
			$result = $this->storageClient->copyBlob( $srcContainer, $srcDir, $dstContainer, $dstDir);
		}
		catch ( Exception $e ) {
			$status->fatal( 'backend-fail-copy', $e->getMessage() );
		}
		return $status;
	}

	/**
	 * @see FileBackend::doDeleteInternal()
	 */
	function doDeleteInternal( array $params ) {
		$status = Status::newGood();

		list( $srcCont, $srcRel ) = $this->resolveStoragePath( $params['src'] );
		if ( $srcRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		// (a) Check the source container
		try { //TODO: Unnecessary --> remove (or better, check in resolveStoragePath?)
			$container = $this->storageClient->getContainer( $srcCont );
		}
		catch ( Exception $e ) {
			$status->fatal( 'backend-fail-delete', $srcRel );
			return $status;
		}

		// (b) Actually delete the object
		try {
			$this->storageClient->deleteBlob( $srcCont, $srcRel );
		}
		catch ( Exception $e ) {
			$status->fatal( 'backend-fail-internal' );
		}

		return $status;
	}

	/**
	 * @see FileBackend::doCreateInternal()
	 */
	function doCreateInternal( array $params ) {
		$status = Status::newGood();

		list( $dstCont, $dstRel ) = $this->resolveStoragePath( $params['dst'] );
		if ( $dstRel === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
			return $status;
		}

		// (a) Check if the destination object already exists
		$blobExists = $this->storageClient->blobExists( $dstCont, $dstRel );
		if ( $blobExists && empty( $params['overwrite'] ) ) { //Blob exists _and_ should not be overridden
			$status->fatal( 'backend-fail-alreadyexists', $params['dst'] );
			return $status;
		}

		// (b) Actually create the object
		try {
			// TODO: how do I know the container exists? Should we call prepare?
			$this->storageClient->putBlobData( $dstCont, $dstRel, $params['content'] );
		}
		catch ( Exception $e ) {
			$status->fatal( 'backend-fail-internal' );
		}

		return $status;
	}

	/**
	 * @see FileBackend::prepare()
	 */

	function doPrepareInternal( $container, $dir, array $params ) {
		$status = Status::newGood();

		list( $c, $dir ) = $this->resolveStoragePath( $params['dir'] );
		if ( $dir === null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dir'] );
			return $status; // invalid storage path
		}
		try {
			$this->storageClient->createContainerIfNotExists( $c );
			// TODO: must this be set anytime prepare is called?
			$this->storageClient->setContainerAcl( $c, Microsoft_WindowsAzure_Storage_Blob::ACL_PUBLIC );

			// TODO: check if readable and writeable
			//$container = $this->storageClient->getContainer( $c );
			//$status->fatal( 'directoryreadonlyerror', $params['dir'] );
			//$status->fatal( 'directorynotreadableerror', $params['dir'] );
		}
		catch (Exception $e ) {
			$status->fatal( 'directorycreateerror', $params['dir'] );
			return $status;
		}
		return $status;
	}


	/**
	 * @see FileBackend::resolveContainerName()
	 */
	protected function resolveContainerName( $container ) {
		//Azure container naming conventions; http://msdn.microsoft.com/en-us/library/dd135715.aspx
		$container = strtolower($container);
		$container = preg_replace( '#[^a-z0-9\-]#', '', $container );
		// TODO: "-test" and "test-" are invalid, too
		$container = preg_replace( '#-{2,}#', '-', $container );

		return $container;
	}

	/**
	 * @see FileBackend::secure()
	 */
	/*
	// TODO: check if we need to override doSecure
	function secure( array $params ) {
		$status = Status::newGood();
		return $status; // badgers? We don't need no steenking badgers!
	}
	*/

	/**
	 * @see FileBackend::fileExists()
	 */
	function doFileExists( array $params ) {die();
		// TODO: Merely renamed this functino from fileExists. Check if more is neccessarey
		// TODO: Off topic here: Prepare function might call some internal function which can be overridden.
		list( $srcCont, $srcRel ) = $this->resolveStoragePath( $params['src'] );
		if ( $srcRel === null ) {
			return false; // invalid storage path
		}

		$exists = $this->storageClient->blobExists( $srcCont, $srcRel );
		return $exists;
	}

	/**
	 * @see FileBackend::getFileTimestamp()
	 */
	// TODO: merely renamed from getFileTimestamp. Check if there are any probs.
	// TODO: Is this method still used?
	function doGetFileTimestamp( array $params ) {
		list( $srcCont, $srcRel ) = $this->resolveStoragePath( $params['src'] );
		if ( $srcRel === null ) {
			return false; // invalid storage path
		}

		$timestamp= false;
		try {
			//TODO Maybe use getBlobData()?
			$blob = $this->storageClient->getBlobInstance( $srcCont, $srcRel );
			$timestamp = wfTimestamp( TS_MW, $blob->LastModified ); //TODO: Timezone?
		} catch ( Exception $e ) {
			// TODO: Log it? What about different types of exceptions?
		}
		return $timestamp;
	}

	/**
	 * @see FileBackend::getFileList()
	 */
	function getFileListInternal( $container, $dir, array $params ) {
		// TODO: merely renamed from getFileList. Check implications (i assume, the list thing is no longer needed.
		//list( $c, $dir ) = $this->resolveStoragePath( $params['dir'] );
		$files = array();
		try {
			if ( $dir === null ) {
				$blobs = $this->storageClient->listBlobs($container);
			}
			else {
				//TODO: Check if $dir really is a startsequence of the blob name
				$blobs = $this->storageClient->listBlobs($container, $dir);
			}

			foreach( $blobs as $blob ) {
				$files[] = $blob->name;
			}
		}
		catch( Exception $e ) {
			return null;
		}

		// if there are no files matching the prefix, return empty array
		return $files;
	}

	/**
	 * @see FileBackend::getLocalCopy()
	 */
	function getLocalCopy( array $params ) {
		list( $srcCont, $srcRel ) = $this->resolveStoragePath( $params['src'] );
		if ( $srcRel === null ) {
			return null;
		}

		// Get source file extension
		$ext = FileBackend::extensionFromPath( $srcRel );
		// Create a new temporary file...
		// TODO: Caution: tempfile should not write a local file.
		$tmpFile = TempFSFile::factory( wfBaseName( $srcRel ) . '_', $ext );
		if ( !$tmpFile ) {
			return null;
		}
		$tmpPath = $tmpFile->getPath();

		try {
			$this->storageClient->getBlob( $srcCont, $srcRel, $tmpPath );
		}
		catch ( Exception $e ) {
			$tmpFile = null;
		}

		return $tmpFile;
	}
	
	/**
	 * @see FileBackend::doFileExists()
	 */
	protected function doGetFileStat( array $params ) {
		// TODO: Refactor
		list( $srcCont, $srcRel ) = $this->resolveStoragePathReal( $params['src'] );
		if ( $srcRel === null ) {
			return false; // invalid storage path
		}

		$timestamp= false;
		$size = false;
		// TODO: need additional checks??
		try {
			// TODO: Maybe use getBlobData()?
			$blob = $this->storageClient->getBlobInstance( $srcCont, $srcRel );
			$timestamp = wfTimestamp( TS_MW, $blob->LastModified ); //TODO: Timezone?
			$size = $blob->Size;
			return array(
				'mtime' => $timestamp,
				'size'  => $size
			);
		} catch ( Exception $e ) { 
			// TODO: Log it? What about different types of exceptions?
		}
		return false;
	}
}
