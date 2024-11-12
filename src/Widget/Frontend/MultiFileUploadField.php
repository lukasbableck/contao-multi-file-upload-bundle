<?php
namespace Lukasbableck\ContaoMultiFileUploadBundle\Widget\Frontend;

use Contao\Config;
use Contao\Dbafs;
use Contao\File;
use Contao\Files;
use Contao\FilesModel;
use Contao\FileUpload;
use Contao\Folder;
use Contao\FrontendUser;
use Contao\StringUtil;
use Contao\System;
use Contao\UploadableWidgetInterface;
use Contao\Validator;
use Contao\Widget;

class MultiFileUploadField extends Widget implements UploadableWidgetInterface {
	protected $strTemplate = 'form_multi_file_upload_field';
	protected $strPrefix = 'widget widget-multi-file-upload';

	public function __construct($arrAttributes = null) {
		parent::__construct($arrAttributes);

		$this->objUploader = new FileUpload();
		$this->objUploader->setName($this->strName);
	}

	public function __get($strKey) {
		return parent::__get($strKey);
	}

	public function parse($arrAttributes = null): string {
		return parent::parse($arrAttributes);
	}

	public function generate() {
		return \sprintf(
			'<input type="file" multiple name="%s[]" id="ctrl_%s" class="upload%s"%s%s',
			$this->strName,
			$this->strId,
			$this->strClass ? ' '.$this->strClass : '',
			$this->getAttributes(),
			$this->strTagEnding
		);
	}

	protected function getMaximumUploadSize() {
		if ($this->maxlength > 0) {
			return $this->maxlength;
		}

		return FileUpload::getMaxUploadSize();
	}

	protected function validator($varInput) {
		$strUploadTo = $this->arrConfiguration['uploadFolder'] ?? 'system/tmp';

		return $this->objUploader->uploadTo($strUploadTo);
	}

	public function __set($strKey, $varValue): void {
		switch ($strKey) {
			case 'maxlength':
				$this->arrConfiguration['maxlength'] = $varValue;
				break;

			case 'mandatory':
				if ($varValue) {
					$this->arrAttributes['required'] = 'required';
				} else {
					unset($this->arrAttributes['required']);
				}
				parent::__set($strKey, $varValue);
				break;

			case 'fSize':
				if ($varValue > 0) {
					$this->arrAttributes['size'] = $varValue;
				}
				break;

			case 'extensions':
				if ($varValue) {
					$this->arrAttributes['accept'] = '.'.strtolower(implode(',.', \is_array($varValue) ? $varValue : StringUtil::trimsplit(',', $varValue)));
				}
				parent::__set($strKey, $varValue);
				break;

			default:
				parent::__set($strKey, $varValue);
				break;
		}
	}

	public function validate(): void {
		// No file specified
		if (!isset($_FILES[$this->strName]) || empty($_FILES[$this->strName]['name'])) {
			if ($this->mandatory) {
				if (!$this->strLabel) {
					$this->addError($GLOBALS['TL_LANG']['ERR']['mdtryNoLabel']);
				} else {
					$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['mandatory'], $this->strLabel));
				}
			}

			return;
		}

		$files = $_FILES[$this->strName];
		$fileCount = \count($files['name']);
		$maxlength_kb = $this->getMaximumUploadSize();
		$maxlength_kb_readable = $this->getReadableSize($maxlength_kb);

		for ($i = 0; $i < $fileCount; ++$i) {
			// Sanitize the filename
			try {
				$files['name'][$i] = StringUtil::sanitizeFileName($files['name'][$i]);
			} catch (\InvalidArgumentException $e) {
				$this->addError($GLOBALS['TL_LANG']['ERR']['filename']);

				return;
			}

			// Invalid file name
			if (!Validator::isValidFileName($files['name'][$i])) {
				$this->addError($GLOBALS['TL_LANG']['ERR']['filename']);

				return;
			}

			// File was not uploaded
			if (!is_uploaded_file($files['tmp_name'][$i])) {
				if (1 == $files['error'][$i] || 2 == $files['error'][$i]) {
					$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb_readable));
				} elseif (3 == $files['error'][$i]) {
					$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['filepartial'], $files['name'][$i]));
				} elseif ($files['error'][$i] > 0) {
					$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['fileerror'], $files['error'][$i], $files['name'][$i]));
				}

				unset($_FILES[$this->strName]);

				return;
			}

			// File is too big
			if ($files['size'][$i] > $maxlength_kb) {
				$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb_readable));
				unset($_FILES[$this->strName]);

				return;
			}

			$objFile = new File($files['name'][$i]);
			$uploadTypes = StringUtil::trimsplit(',', strtolower($this->extensions));

			// File type is not allowed
			if (!\in_array($objFile->extension, $uploadTypes)) {
				$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['filetype'], $objFile->extension));
				unset($_FILES[$this->strName]);

				return;
			}

			if ($arrImageSize = @getimagesize($files['tmp_name'][$i])) {
				$intImageWidth = $this->maxImageWidth ?: Config::get('imageWidth');

				// Image exceeds maximum image width
				if ($intImageWidth > 0 && $arrImageSize[0] > $intImageWidth) {
					$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['filewidth'], $files['name'][$i], $intImageWidth));
					unset($_FILES[$this->strName]);

					return;
				}

				$intImageHeight = $this->maxImageHeight ?: Config::get('imageHeight');

				// Image exceeds maximum image height
				if ($intImageHeight > 0 && $arrImageSize[1] > $intImageHeight) {
					$this->addError(\sprintf($GLOBALS['TL_LANG']['ERR']['fileheight'], $files['name'][$i], $intImageHeight));
					unset($_FILES[$this->strName]);

					return;
				}
			}

			// Store the file on the server if enabled
			if (!$this->hasErrors()) {
				$this->varValue = $_FILES[$this->strName];

				if ($this->storeFile) {
					$intUploadFolder = $this->uploadFolder;

					// Overwrite the upload folder with user's home directory
					if ($this->useHomeDir && System::getContainer()->get('contao.security.token_checker')->hasFrontendUser()) {
						$user = FrontendUser::getInstance();

						if ($user->assignDir && $user->homeDir) {
							$intUploadFolder = $user->homeDir;
						}
					}

					$objUploadFolder = FilesModel::findByUuid($intUploadFolder);

					// The upload folder could not be found
					if (null === $objUploadFolder) {
						throw new \Exception("Invalid upload folder ID $intUploadFolder");
					}

					$strUploadFolder = $objUploadFolder->path;
					$projectDir = System::getContainer()->getParameter('kernel.project_dir');

					// Store the file if the upload folder exists
					if ($strUploadFolder && is_dir($projectDir.'/'.$strUploadFolder)) {
						// Do not overwrite existing files
						if ($this->doNotOverwrite && file_exists($projectDir.'/'.$strUploadFolder.'/'.$files['name'][$i])) {
							$offset = 1;

							$arrAll = Folder::scan($projectDir.'/'.$strUploadFolder, true);
							$arrFiles = preg_grep('/^'.preg_quote($objFile->filename, '/').'.*\.'.preg_quote($objFile->extension, '/').'/', $arrAll);

							foreach ($arrFiles as $strFile) {
								if (preg_match('/__[0-9]+\.'.preg_quote($objFile->extension, '/').'$/', $strFile)) {
									$strFile = str_replace('.'.$objFile->extension, '', $strFile);
									$intValue = (int) substr($strFile, strrpos($strFile, '_') + 1);

									$offset = max($offset, $intValue);
								}
							}

							$files['name'][$i] = str_replace($objFile->filename, $objFile->filename.'__'.++$offset, $files['name'][$i]);
						}

						// Move the file to its destination
						$filesObj = Files::getInstance();
						$filesObj->move_uploaded_file($files['tmp_name'][$i], $strUploadFolder.'/'.$files['name'][$i]);
						$filesObj->chmod($strUploadFolder.'/'.$files['name'][$i], 0o666 & ~umask());

						$strUuid = null;
						$strFile = $strUploadFolder.'/'.$files['name'][$i];

						// Generate the DB entries
						if (Dbafs::shouldBeSynchronized($strFile)) {
							$objModel = FilesModel::findByPath($strFile);

							if (null === $objModel) {
								$objModel = Dbafs::addResource($strFile);
							}

							$strUuid = StringUtil::binToUuid($objModel->uuid);

							// Update the hash of the target folder
							Dbafs::updateFolderHashes($strUploadFolder);
						}

						$this->varValue['tmp_name'][$i] = $projectDir.'/'.$strFile;
						$this->varValue['uploaded'][$i] = true;
						$this->varValue['uuid'][$i] = $strUuid;

						System::getContainer()->get('monolog.logger.contao.files')->info('File "'.$strUploadFolder.'/'.$files['name'][$i].'" has been uploaded');
					}
				}
			}
		}

		unset($_FILES[$this->strName]);
	}
}
