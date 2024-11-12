<?php
namespace Lukasbableck\ContaoMultiFileUploadBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Lukasbableck\ContaoMultiFileUploadBundle\ContaoMultiFileUploadBundle;

class Plugin implements BundlePluginInterface {
	public function getBundles(ParserInterface $parser): array {
		return [BundleConfig::create(ContaoMultiFileUploadBundle::class)->setLoadAfter([ContaoCoreBundle::class])];
	}
}
