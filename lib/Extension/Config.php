<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Extension;

use Pimcore\Config as PimcoreConfig;
use Pimcore\File;

/**
 * @internal
 *
 * @deprecated
 */
class Config
{
    private ?PimcoreConfig\Config $config = null;

    private ?string $file = null;

    /**
     * @return PimcoreConfig\Config
     */
    public function loadConfig(): PimcoreConfig\Config
    {
        if (!$this->config) {
            if ($this->configFileExists()) {
                $this->config = new PimcoreConfig\Config(include $this->locateConfigFile(), true);

                if (isset($this->config->bundle) && $this->config->bundle->count() > 0) {
                    trigger_deprecation(
                        'pimcore/pimcore',
                        '10.5',
                        'Registering bundles through extensions.php is deprecated and will not work on Pimcore 11. Use config/bundles.php to register/deregister bundles.'
                    );
                }
            }

            if (!$this->config) {
                $this->config = new PimcoreConfig\Config([], true);
            }
        }

        return $this->config;
    }

    /**
     * @param PimcoreConfig\Config $config
     */
    public function saveConfig(PimcoreConfig\Config $config)
    {
        $this->config = $config;

        File::putPhpFile(
            $this->locateConfigFile(),
            to_php_data_file_format($config->toArray())
        );
    }

    /**
     * @return string
     */
    public function locateConfigFile(): string
    {
        if (null === $this->file) {
            $this->file = PimcoreConfig::locateConfigFile('extensions.php');
        }

        return $this->file;
    }

    /**
     * @return bool
     */
    public function configFileExists(): bool
    {
        $file = $this->locateConfigFile();

        return file_exists($file);
    }
}
