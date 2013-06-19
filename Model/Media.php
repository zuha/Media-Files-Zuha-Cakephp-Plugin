<?php
class Media extends MediaAppModel {

/**
 * An array of file types we accept to the media plugin.
 */
	public $supportedFileExtensions = array('pdf', 'doc', 'docx', 'ods', 'odt');

/**
 * An array of video types we accept to the media plugin.
 */
	public $supportedVideoExtensions = array('mpg', 'mov', 'wmv', 'rm', '3g2', '3gp', '3gp2', '3gpp', '3gpp2', 'avi', 'divx', 'dv', 'dv-avi', 'dvx', 'f4v', 'flv', 'h264', 'hdmov', 'm4v', 'mkv', 'mp4', 'mp4v', 'mpe', 'mpeg', 'mpeg4', 'mpg', 'nsv', 'qt', 'swf', 'xvid');


/**
 * An array of audio types we accept to the media plugin.
 */
	public $supportedAudioExtensions = array('aif', 'mid', 'midi', 'mka', 'mp1', 'mp2', 'mp3', 'mpa', 'wav', 'aac', 'flac', 'ogg', 'ra', 'raw', 'wma');

	public $name = 'Media';

	public $belongsTo = array(
	    'User' => array(
			'className' => 'Users.User',
			'foreignKey' => 'user_id'
			)
		);


	public function __construct($id = false, $table = null, $ds = null) {
		parent::__construct($id, $table, $ds);
		$this->themeDirectory = ROOT.DS.SITE_DIR.DS.'Locale'.DS.'View'.DS.WEBROOT_DIR.DS.'media'.DS;
		$this->uploadFileDirectory = 'docs';
		$this->uploadVideoDirectory =  'videos';
		$this->uploadAudioDirectory = 'audio';
		$this->uploadImageDirectory = 'images';
		$this->order = array("{$this->alias}.created");
	}


	public function beforeSave($options) {
		$this->data['Media']['model'] = !empty($this->data['Media']['model']) ? $this->data['Media']['model'] : 'Media';
		$this->plugin = strtolower(ZuhaInflector::pluginize($this->data['Media']['model']));
		$this->_createDirectories();
		$this->data = $this->_handleRecordings($this->data);
		$this->data = $this->_handleCanvasImages($this->data);
		$this->fileExtension = $this->getFileExtension($this->data['Media']['filename']['name']);
		
		return $this->processFile();
	}//beforeSave()

	
	public function processFile() {
		if(in_array($this->fileExtension, $this->supportedFileExtensions)) {
			$this->data['Media']['type'] = 'docs';
			$this->data = $this->uploadFile($this->data);
		} elseif(in_array($this->fileExtension, $this->supportedImageExtensions)) {
			$this->data['Media']['type'] = 'images';
			$this->data = $this->uploadFile($this->data);
		} elseif (in_array($this->fileExtension, $this->supportedVideoExtensions)) {
			 $this->data['Media']['type'] = 'videos';
			// $this->data = $this->encode($this);
			echo "Encoding support was removed, needs work here.";
			break;
		} elseif (in_array($this->fileExtension, $this->supportedAudioExtensions)) {
			 $this->data['Media']['type'] = 'audio';
			 // $this->data = $this->encode($this);
			 echo "Encoding support was removed, needs work here.";
			 break;
		} else {
			// an unsupported file type
			return false;
		}
		return true;
	}

    /**
     *
     * @param type $results
     * @param type $primary
     * @return array 
     */
    public function afterFind($results, $primary = false) {

		foreach($results as $key => $val) {
			if(isset($val['Media']['filename'])) {

				# what formats did we receive from the encoder?
				$outputs = json_decode($val['Media']['filename'], true);
				
				# audio files have 1 output currently.. arrays are not the same.. make them so.
				/** @todo this part is kinda hacky.. **/
				if($val['Media']['type'] == 'audio') {
					$temp['outputs'] = $outputs['outputs'];
					$outputs = null;
					$outputs['outputs'][0] = $temp['outputs'];
				}
			
				if($val['Media']['type'] == 'videos') {
					$outputArray = $extensionArray = null;
					if (!empty($outputs)) {
						foreach ($outputs['outputs'] as $output) {
							$outputArray[] = 'http://' . $_SERVER['HTTP_HOST'] . '/media/media/stream/' . $val['Media']['filename'] . '/' . $output['label'];
							$extensionArray[] = $output['label'];
						}
					}
					# set the modified ['filename']
					$results[$key]['Media']['filename'] = $outputArray;
					$results[$key]['Media']['ext'] = $extensionArray;
				}
			}
		}
		return $results;
    }


/**
 * This is a valid callback that comes with the Rateable plugin
 * It is being kept here for future reference/use
 * @param array $data
 */
	public function afterRate($data) {
		#debug($data);
	}



/**
 * Get the extension of a given file path
 *
 * @param {string} 		A file name/path string
 */
    function getFileExtension($filepath) {
        preg_match('/[^?]*/', $filepath, $matches);
        $string = $matches[0];

        $pattern = preg_split('/\./', $string, -1, PREG_SPLIT_OFFSET_CAPTURE);

        # check if there is any extension
        if(count($pattern) == 1) {
            return FALSE;
        }

        if(count($pattern) > 1) {
            $filenamepart = $pattern[count($pattern)-1][0];
            preg_match('/[^?]*/', $filenamepart, $matches);
            return strtolower($matches[0]);
        }
    }


/**
 * Handles an uloaded file (ie. doc, pdf, etc)
 */
	public function uploadFile($data) {
		$uuid = $this->_generateUUID();
		$newFile =  $this->themeDirectory . strtolower(ZuhaInflector::pluginize($data['Media']['model'])) . DS . $this->uploadFileDirectory . DS . $uuid .'.'. $this->fileExtension;
		if (rename($data['Media']['filename']['tmp_name'], $newFile)) :
			$data['Media']['filename'] = $uuid; // change the filename to just the filename
			$data['Media']['extension'] = $this->fileExtension; // change the extension to just the extension
			$data['Media']['type'] = 'docs';
			return $data;
		else :
			throw new Exception(__d('media', 'File Upload of ' . $data['Media']['filename']['name'] . ' to ' . $newFile . '  Failed'));
		endif;
	}


/**
 * Recordings were saved to the recording server, and now we need to move them to the local server.
 *
 */
	private function _handleRecordings($data) {
		if (!empty($data['Media']['type']) && $data['Media']['type'] == 'record') {
			$fileName = $data['Media']['uuid'];
			$serverFile = '/home/razorit/source/red5-read-only/dist/webapps/oflaDemo/streams/'.$fileName.'.flv';
			$localFile = $this->themeDirectory . $this->plugin . DS . 'videos' . DS . $fileName.'.flv';
			#$url = '/theme/default/media/'.$this->pluginFolder.'/videos/'.$fileName.'.flv';

			if (file_exists($serverFile)) {
				if(rename($serverFile, $localFile)) {
					#echo $url = '/theme/default/media/'.$this->pluginFolder.'/videos/'.$fileName.'.flv';
				} else {
					return false;
				}
			} else {
				return false;
			}

			$data['Media']['filename']['name'] = $fileName.'.flv';
			$data['Media']['filename']['type'] = 'video/x-flv';
			$data['Media']['filename']['tmp_name'] = $localFile;
			$data['Media']['filename']['error'] = 0;
			$data['Media']['filename']['size'] = 99999; //
		}
		return $data;
	}

	private function _handleCanvasImages($data) {
		if ( !empty($data['Media']['canvasImageData']) ) {
			
			$canvasImageData = str_replace('data:image/png;base64,', '', $data['Media']['canvasImageData']);
			$decodedImage = base64_decode($canvasImageData);
			
			$filename = preg_replace("/[^\w\s\d\-_~,;:\[\]\(\]]|[\.]{2,}/", '', $data['Media']['title'].'_'.uniqid());
			$saveName = $this->themeDirectory . $this->plugin . DS . 'images' . DS . $filename.'.png';
			
			$fopen = fopen($saveName, 'wb');
			fwrite($fopen, $decodedImage);
			fclose($fopen);
			
			$data['Media']['filename']['name'] = $filename.'.png';
			$data['Media']['filename']['type'] = 'image/png';
			$data['Media']['filename']['tmp_name'] = $saveName;
			$data['Media']['filename']['error'] = 0;
		}
		return $data;
	}

/**
 * Create the directories for this plugin if they aren't there already.
 */
	private function _createDirectories() {
		if (!file_exists($this->themeDirectory . $this->plugin)) {
			if (
				mkdir($this->themeDirectory . $this->plugin) &&
				mkdir($this->themeDirectory . $this->plugin . DS . 'videos') &&
				mkdir($this->themeDirectory . $this->plugin . DS . 'docs') &&
				mkdir($this->themeDirectory . $this->plugin . DS . 'audio') &&
				mkdir($this->themeDirectory . $this->plugin . DS . 'images') &&
				mkdir($this->themeDirectory . $this->plugin . DS . 'images' . DS . 'thumbs')
				) {
				return true;
			} else {
				return false;
			}
		} else {return true;
		}
	}


}//class{}