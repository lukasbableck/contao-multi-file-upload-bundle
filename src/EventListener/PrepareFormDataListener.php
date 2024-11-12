<?php
namespace Lukasbableck\ContaoMultiFileUploadBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Form;
use Contao\FormFieldModel;

#[AsHook('prepareFormData', priority: -16)]
class PrepareFormDataListener {
	public function __invoke(array &$submittedData, array $labels, array $fields, Form $form, array &$files): void {
		$formFields = FormFieldModel::findPublishedByPid($form->id);
		foreach ($formFields as $formField) {
			if ('multi_file_upload_field' == $formField->type) {
				$filesArr = $files[$formField->name];
				$fileCount = \count($filesArr['name']);
				for ($i = 0; $i < $fileCount; ++$i) {
					$files[$formField->name.'_'.$i] = [
						'name' => $filesArr['name'][$i],
						'type' => $filesArr['type'][$i],
						'full_path' => $filesArr['full_path'][$i],
						'tmp_name' => $filesArr['tmp_name'][$i],
						'error' => $filesArr['error'][$i],
						'size' => $filesArr['size'][$i],
					];
				}
				unset($files[$formField->name]);
			}
		}
	}
}
